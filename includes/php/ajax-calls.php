<?php
/**
 * Throttling function to prevent abuse
 * Limits requests per user per time window
 */
function sticky_comment_check_throttle($action = 'general', $max_requests = 10, $time_window = 60) {
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $transient_key = "sticky_comment_throttle_{$user_id}_{$action}";
    } else {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '0';
        $transient_key = "sticky_comment_throttle_guest_" . md5($ip . $action);
    }
    $requests = get_transient($transient_key);

    if ($requests === false) {
        // First request in this window
        set_transient($transient_key, 1, $time_window);
        return true;
    }

    if ($requests >= $max_requests) {
        return false; // Throttled
    }

    // Increment counter
    set_transient($transient_key, $requests + 1, $time_window);
    return true;
}

/**
 * Send email notification when a sticky note is saved.
 * Returns 'sent', 'failed', or 'skipped'.
 */
function sticky_comment_send_notification($type, $note_id, $content, $post_id, $user_id, $guest_ctx, $assigned_to, $priority, $title, $device) {
    error_log('[Sticky Notes][Email] === START email notification (type: ' . $type . ', note #' . $note_id . ') ===');

    $notification_email = get_option('sticky_comment_notification_email', '');
    if (empty($notification_email) || !is_email($notification_email)) {
        error_log('[Sticky Notes][Email] SKIPPED — notification email is empty or invalid: "' . $notification_email . '"');
        return array('status' => 'skipped');
    }
    error_log('[Sticky Notes][Email] Notification recipient: ' . $notification_email);

    // Fetch full note from DB for comments and images
    global $wpdb;
    $table_name = $wpdb->prefix . 'sticky_notes';
    $note_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $note_id));

    // Resolve author name and email
    $author = __('Unknown', 'sticky-comment');
    $author_email = '';
    if ($user_id > 0) {
        $user = get_user_by('id', $user_id);
        if ($user) {
            $author = $user->display_name ? $user->display_name : $user->user_login;
            $author_email = $user->user_email;
        }
    } elseif ($guest_ctx !== null) {
        $author = __('Guest', 'sticky-comment');
    }

    // Resolve page URL
    $page_url = '';
    if ($post_id > 0 && get_post_status($post_id)) {
        $page_url = get_permalink($post_id);
    }
    if (empty($page_url) && isset($_POST['page_url'])) {
        $page_url = esc_url_raw($_POST['page_url']);
    }

    // Map priority
    $priority_labels = array(1 => 'Low', 2 => 'Medium', 3 => 'High');
    $priority_label = isset($priority_labels[$priority]) ? $priority_labels[$priority] : 'Medium';

    // Resolve comments
    $comments_text = '';
    if ($note_row && !empty($note_row->comments)) {
        $dec = sticky_comment_decrypt($note_row->comments);
        $comments_arr = json_decode($dec, true);
        if (is_array($comments_arr) && count($comments_arr) > 0) {
            $comments_text .= "\nComments (" . count($comments_arr) . "):\n";
            foreach ($comments_arr as $c) {
                $c_author = isset($c['first_name']) && $c['first_name'] !== '' ? $c['first_name'] : (isset($c['user_email']) ? $c['user_email'] : 'Unknown');
                $c_date = isset($c['created_at']) ? $c['created_at'] : '';
                $c_content = isset($c['content']) ? $c['content'] : '';
                $comments_text .= "  - " . $c_author . ($c_date ? " (" . $c_date . ")" : "") . ": " . $c_content . "\n";
            }
        }
    }

    // Resolve image URLs
    $images_text = '';
    if ($note_row && !empty($note_row->images)) {
        $dec = sticky_comment_decrypt($note_row->images);
        $image_ids = json_decode($dec, true);
        if (is_array($image_ids) && count($image_ids) > 0) {
            $images_text .= "\nImages (" . count($image_ids) . "):\n";
            foreach ($image_ids as $img_id) {
                $img_id = intval($img_id);
                if ($img_id > 0) {
                    $url = wp_get_attachment_url($img_id);
                    if ($url) {
                        $images_text .= "  - " . $url . "\n";
                    }
                }
            }
        }
    }

    $action_label = ($type === 'new') ? __('New Sticky Note Created', 'sticky-comment') : __('Sticky Note Updated', 'sticky-comment');

    $subject = $action_label . ' (#' . $note_id . ')';

    $site_name = get_option('blogname', 'WordPress');
    $site_url = home_url();

    $body  = $action_label . "\n";
    $body .= str_repeat('-', 40) . "\n\n";
    $body .= "Website: " . $site_name . "\n";
    $body .= "Domain: " . $site_url . "\n";
    if (!empty($title)) {
        $body .= "Title: " . $title . "\n";
    }
    $body .= "Author: " . $author . (!empty($author_email) ? " (" . $author_email . ")" : "") . "\n";
    $body .= "Priority: " . $priority_label . "\n";
    if (!empty($assigned_to)) {
        $body .= "Assigned To: " . $assigned_to . "\n";
    }
    $body .= "Device: " . (!empty($device) ? ucfirst($device) : 'Unknown') . "\n";
    if (!empty($page_url)) {
        $sv_url = add_query_arg('sv', $note_id, $page_url);
        $body .= "Link: " . $sv_url . "\n";
    }
    $body .= "\nContent:\n" . $content . "\n";
    $body .= $comments_text;
    $body .= $images_text;

    // Capture PHPMailer errors via wp_mail_failed hook
    $mail_error = null;
    $capture_error = function($wp_error) use (&$mail_error) {
        $mail_error = $wp_error;
        error_log('[Sticky Notes][Email] wp_mail_failed hook fired — ' . $wp_error->get_error_message());
        $err_data = $wp_error->get_error_data();
        if (!empty($err_data)) {
            error_log('[Sticky Notes][Email] wp_mail_failed data: ' . (is_string($err_data) ? $err_data : wp_json_encode($err_data)));
        }
    };
    add_action('wp_mail_failed', $capture_error);

    $admin_email = get_option('admin_email', '');
    $site_name = get_option('blogname', 'Sticky Notes');
    $headers = array();
    if (!empty($admin_email) && is_email($admin_email)) {
        $headers[] = 'From: ' . $site_name . ' <' . $admin_email . '>';
    }

    // Log mail environment before sending
    error_log('[Sticky Notes][Email] From header: ' . (!empty($headers) ? implode(', ', $headers) : '(none, using server default)'));
    error_log('[Sticky Notes][Email] Admin email: ' . ($admin_email ?: '(not set)'));
    error_log('[Sticky Notes][Email] Subject: ' . $subject);
    error_log('[Sticky Notes][Email] Body length: ' . strlen($body) . ' chars');
    error_log('[Sticky Notes][Email] PHP mail function exists: ' . (function_exists('mail') ? 'yes' : 'NO'));

    // Detect if an SMTP plugin is overriding wp_mail
    $mailer_info = 'default PHP mail()';
    if (class_exists('WPMailSMTP\\MailCatcherInterface') || function_exists('wp_mail_smtp')) {
        $mailer_info = 'WP Mail SMTP plugin';
    } elseif (class_exists('PostmanOptions')) {
        $mailer_info = 'Post SMTP plugin';
    } elseif (class_exists('FluentMail\\App\\Services\\Mailer\\Manager')) {
        $mailer_info = 'FluentSMTP plugin';
    } elseif (has_filter('wp_mail')) {
        $mailer_info = 'custom wp_mail filter detected';
    }
    error_log('[Sticky Notes][Email] Mailer: ' . $mailer_info);

    error_log('[Sticky Notes][Email] Calling wp_mail()...');
    $sent = wp_mail($notification_email, $subject, $body, $headers);
    error_log('[Sticky Notes][Email] wp_mail() returned: ' . ($sent ? 'TRUE' : 'FALSE'));

    remove_action('wp_mail_failed', $capture_error);

    if ($sent) {
        error_log('[Sticky Notes][Email] === SUCCESS — email accepted for delivery to ' . $notification_email . ' for note #' . $note_id . ' (' . $type . ') ===');
        error_log('[Sticky Notes][Email] NOTE: "accepted for delivery" means PHP/mailer did not report an error. If the email does not arrive, check your hosting mail logs or spam folder.');
        return array('status' => 'sent');
    } else {
        $error_msg = 'Unknown error';
        $user_msg = 'Email could not be sent. Please check your server mail configuration.';
        if ($mail_error && is_wp_error($mail_error)) {
            $error_msg = $mail_error->get_error_message();
            $error_data = $mail_error->get_error_data();
            if (!empty($error_data)) {
                $error_msg .= ' | Data: ' . (is_string($error_data) ? $error_data : wp_json_encode($error_data));
            }
            // User-friendly message based on common errors
            $raw = $mail_error->get_error_message();
            if (stripos($raw, 'Could not instantiate mail function') !== false) {
                $user_msg = 'Mail server is not configured. Contact your hosting provider or install an SMTP plugin.';
            } elseif (stripos($raw, 'Invalid address') !== false) {
                $user_msg = 'Invalid email address in settings. Please check the From or Notification email.';
            } elseif (stripos($raw, 'SMTP connect() failed') !== false || stripos($raw, 'Connection refused') !== false) {
                $user_msg = 'Could not connect to the mail server. Check your SMTP settings.';
            }
        }
        error_log('[Sticky Notes][Email] === FAILED — email to ' . $notification_email . ' for note #' . $note_id . ' (' . $type . ') — Error: ' . $error_msg . ' ===');
        return array('status' => 'failed', 'reason' => $user_msg);
    }
}

// AJAX handler for storing sticky comment
function sticky_comment_save() {
    // Check throttling first (before other checks to save resources)
    $throttle_limit = get_option('sticky_comment_throttle_limit', 20); // requests per minute
    if (!sticky_comment_check_throttle('save_note', $throttle_limit, 60)) {
        wp_send_json_error(array(
            'message' => __('Too many requests. Please wait a moment before trying again.', 'sticky-comment'),
            'retry_after' => 60
        ));
    }

    // Check nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sticky_comment_nonce')) {
        wp_send_json_error(array('message' => __('Your session expired. Please refresh the page and try again.', 'sticky-comment')));
    }

    $guest_ctx = null;
    if (!is_user_logged_in()) {
        if (!function_exists('sticky_comment_get_guest_context_from_request')) {
            wp_send_json_error(array('message' => __('You must be logged in to create notes.', 'sticky-comment')));
        }
        $guest_ctx = sticky_comment_get_guest_context_from_request();
        if ($guest_ctx === null || $guest_ctx['guest_id'] === '') {
            wp_send_json_error(array('message' => __('You must be logged in to create notes.', 'sticky-comment')));
        }
    } else {
        $min_cap = get_option('sticky_comment_settings_min_cap', 'manage_options');
        if (!current_user_can($min_cap)) {
            wp_send_json_error(array('message' => __('You do not have permission to create notes.', 'sticky-comment')));
        }
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'sticky_notes';
    if (function_exists('sticky_comment_ensure_table_exists')) {
        $ok = sticky_comment_ensure_table_exists();
        if (!$ok) {
            wp_send_json_error(array('message' => __('Database table is not available.', 'sticky-comment')));
        }
    }

    $note_id = isset($_POST['note_id']) ? intval($_POST['note_id']) : 0;
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if ($post_id === 0) {
        $page_url = isset($_POST['page_url']) ? esc_url_raw($_POST['page_url']) : '';
        if (!empty($page_url)) {
            $post_id = absint(crc32($page_url));
        }
    }
    $max_notes = get_option('sticky_comment_max_notes', 10);
    if ($guest_ctx !== null) {
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE post_id = %d AND shared_link_id = %d AND is_completed = 0 AND is_done = 0",
            $post_id,
            $guest_ctx['link_id']
        ));
    } else {
        $count = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE post_id = %d AND is_completed = 0 AND is_done = 0", $post_id)
        );
    }
    if ($count >= $max_notes && empty($_POST['note_id'])) {
        wp_send_json_error('Maximum number of sticky notes reached for this post.');
        return;
    }
    $element_path = isset($_POST['element_path']) ? sanitize_text_field($_POST['element_path']) : '';
    if (!empty($element_path) && strlen($element_path) > 65000) {
        $element_path = substr($element_path, 0, 65000);
    }
    $user_id = $guest_ctx !== null ? 0 : get_current_user_id();
    $content = isset($_POST['content']) ? sanitize_textarea_field($_POST['content']) : '';
    $x = isset($_POST['x']) ? intval($_POST['x']) : 0;
    $y = isset($_POST['y']) ? intval($_POST['y']) : 0;
    $is_collapsed = isset($_POST['is_collapsed']) ? intval($_POST['is_collapsed']) : 0;
    $priority = isset($_POST['priority']) ? intval($_POST['priority']) : 2; // 1=Low,2=Medium,3=High
    if ($priority < 1 || $priority > 3) { $priority = 2; }
    $assigned_to = isset($_POST['assigned_to']) ? sanitize_text_field($_POST['assigned_to']) : '';
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $device = isset($_POST['device']) ? sanitize_text_field($_POST['device']) : '';
    if (!in_array($device, array('mobile','tablet','desktop'), true)) {
        $device = '';
    }

    // Additional title validation
    if (!empty($title)) {
        // Ensure title is not too long (should match frontend limit)
        $title = substr($title, 0, 100);
        // Additional XSS protection (though sanitize_text_field should handle this)
        $title = wp_strip_all_tags($title);
        $title = trim($title);
    }
    // Normalize: treat a raw '0' as empty/unassigned
    if ($assigned_to === '0' || $assigned_to === 0) {
        $assigned_to = '';
    }
    $is_completed = isset($_POST['is_completed']) ? intval($_POST['is_completed']) : 0;
    $is_done = isset($_POST['is_done']) ? intval($_POST['is_done']) : $is_completed;
    // Images payload comes as JSON string; keep as-is but cap size
    $images_json = isset($_POST['images']) ? wp_unslash($_POST['images']) : '';
    if (!empty($images_json) && strlen($images_json) > 65000) {
        $images_json = substr($images_json, 0, 65000);
    }

    if ($note_id > 0) {
        if ($guest_ctx !== null) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, guest_author_id, shared_link_id FROM $table_name WHERE id = %d",
                $note_id
            ), ARRAY_A);
            if (!$existing || $existing['guest_author_id'] !== $guest_ctx['guest_id'] || (int) $existing['shared_link_id'] !== (int) $guest_ctx['link_id']) {
                wp_send_json_error(array('message' => __('You can only edit your own notes.', 'sticky-comment')));
            }
        }
        $data = array(
            'content' => sticky_comment_encrypt($content),
            'position_x' => $x,
            'position_y' => $y,
            'is_collapsed' => $is_collapsed,
            'updated_at' => current_time('mysql'),
        );
        $data['assigned_to'] = sticky_comment_encrypt($assigned_to);
        if ($title !== '') {
            $data['title'] = sticky_comment_encrypt($title);
        }
        $data['is_completed'] = $is_completed;
        $data['is_done'] = $is_done;
        $data['priority'] = $priority;
        if ($device !== '') {
            $data['device'] = $device;
        }
        if ($images_json !== '') {
            $data['images'] = sticky_comment_encrypt($images_json);
        }
        if (!empty($element_path)) {
            $data['element_path'] = sticky_comment_encrypt($element_path);
        }
        $where = array('id' => $note_id, 'user_id' => $user_id);
        if ($guest_ctx !== null) {
            $where = array('id' => $note_id, 'guest_author_id' => $guest_ctx['guest_id'], 'shared_link_id' => $guest_ctx['link_id']);
        }
        $updated = $wpdb->update($table_name, $data, $where);
        if ($updated !== false) {
            $email_result = array('status' => 'skipped');
            $should_notify = isset($_POST['notify']) && $_POST['notify'] === '1';
            if ($should_notify) {
                $email_type = (isset($_POST['is_new']) && $_POST['is_new'] === '1') ? 'new' : 'update';
                $email_result = sticky_comment_send_notification($email_type, $note_id, $content, $post_id, $user_id, $guest_ctx, $assigned_to, $priority, $title, $device);
            }
            $response = array('message' => 'Sticky note updated', 'email_sent' => $email_result['status']);
            if (!empty($email_result['reason'])) {
                $response['email_error'] = $email_result['reason'];
            }
            wp_send_json_success($response);
        } else {
            wp_send_json_error('Database update failed');
        }
    } else {
        $insert_data = array(
            'post_id' => $post_id,
            'user_id' => $user_id,
            'content' => sticky_comment_encrypt($content),
            'images' => $images_json !== '' ? sticky_comment_encrypt($images_json) : null,
            'position_x' => $x,
            'position_y' => $y,
            'element_path' => sticky_comment_encrypt($element_path),
            'assigned_to' => sticky_comment_encrypt($assigned_to),
            'title' => sticky_comment_encrypt($title),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'is_collapsed' => $is_collapsed,
            'is_completed' => $is_completed,
            'is_done' => $is_done,
            'priority' => $priority,
            'device' => $device,
        );
        if ($guest_ctx !== null) {
            $insert_data['guest_author_id'] = $guest_ctx['guest_id'];
            $insert_data['shared_link_id'] = $guest_ctx['link_id'];
        }
        $inserted = $wpdb->insert($table_name, $insert_data);
        if ($inserted) {
            $new_note_id = $wpdb->insert_id;
            $email_result = array('status' => 'skipped');
            $should_notify = isset($_POST['notify']) && $_POST['notify'] === '1';
            if ($should_notify) {
                $email_result = sticky_comment_send_notification('new', $new_note_id, $content, $post_id, $user_id, $guest_ctx, $assigned_to, $priority, $title, $device);
            }
            $response = array('message' => 'Sticky note saved', 'note_id' => $new_note_id, 'email_sent' => $email_result['status']);
            if (!empty($email_result['reason'])) {
                $response['email_error'] = $email_result['reason'];
            }
            wp_send_json_success($response);
        } else {
            wp_send_json_error('Database insert failed');
        }
    }
}
add_action('wp_ajax_sticky_comment', 'sticky_comment_save');
add_action('wp_ajax_nopriv_sticky_comment', 'sticky_comment_save');


// AJAX handler to fetch sticky notes by post ID (logged-in or valid guest link)
add_action('wp_ajax_get_sticky_notes_by_post_id', 'get_sticky_notes_by_post_id');
add_action('wp_ajax_nopriv_get_sticky_notes_by_post_id', 'get_sticky_notes_by_post_id');

function get_sticky_notes_by_post_id() {
    // Check throttling first
    if (!sticky_comment_check_throttle('get_notes', 30, 60)) { // Allow more requests for reading
        wp_send_json_error(array(
            'message' => __('Too many requests. Please wait a moment before trying again.', 'sticky-comment'),
            'retry_after' => 60
        ));
    }

    // Check nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sticky_comment_nonce')) {
        wp_send_json_error('Invalid nonce');
    }

    // View-only mode: allow fetching a single note by ID without authentication
    $is_view_only = !empty($_POST['view_only']) && intval($_POST['view_only']) === 1;
    $view_note_id = isset($_POST['view_note_id']) ? intval($_POST['view_note_id']) : 0;

    $guest_ctx = null;
    if ($is_view_only && $view_note_id > 0) {
        // View-only access — no login or guest context required
    } elseif (!is_user_logged_in()) {
        if (!function_exists('sticky_comment_get_guest_context_from_request')) {
            wp_send_json_error('User must be logged in');
        }
        $guest_ctx = sticky_comment_get_guest_context_from_request();
        if ($guest_ctx === null) {
            wp_send_json_error('User must be logged in');
        }
    } else {
        $min_cap = get_option('sticky_comment_settings_min_cap', 'manage_options');
        if (!current_user_can($min_cap)) {
            wp_send_json_error('Insufficient permissions');
        }
    }

    global $wpdb;
    if (!sticky_comment_ensure_table_exists()) {
        wp_send_json_error('Database table is not available');
    }

    $table_name = $wpdb->prefix . 'sticky_notes';

    // View-only: fetch only the single requested note
    if ($is_view_only && $view_note_id > 0) {
        $sql = "SELECT id, post_id, user_id, guest_author_id, shared_link_id, content, title, images, comments, position_x, position_y, element_path, assigned_to, is_collapsed, is_completed, is_done, priority, device
                 FROM $table_name WHERE id = %d LIMIT 1";
        $notes = $wpdb->get_results($wpdb->prepare($sql, $view_note_id), ARRAY_A);
    } else {

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if ($post_id === 0) {
        $page_url = isset($_POST['page_url']) ? esc_url_raw($_POST['page_url']) : '';
        if (!empty($page_url)) {
            $post_id = absint(crc32($page_url));
        }
    }
    if ($post_id === 0) {
        wp_send_json_error('Invalid post ID');
    }

    $include_id = isset($_POST['include_id']) ? intval($_POST['include_id']) : 0;
    $include_completed = !empty($_POST['include_completed']) ? 1 : 0;

    $where_guest = '';
    $link_id = $guest_ctx !== null ? $guest_ctx['link_id'] : 0;
    if ($guest_ctx !== null) {
        $where_guest = ' AND (user_id > 0 OR shared_link_id = %d)';
    }

    if ($include_completed) {
        $params = array($post_id);
        if ($guest_ctx !== null) {
            $params[] = $link_id;
        }
        $sql = "SELECT id, post_id, user_id, guest_author_id, shared_link_id, content, title, images, comments, position_x, position_y, element_path, assigned_to, is_collapsed, is_completed, is_done, priority, device
                 FROM $table_name WHERE post_id = %d" . $where_guest . " ORDER BY updated_at DESC";
        $notes = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    } elseif ($include_id > 0) {
        $params = array($post_id, $include_id);
        if ($guest_ctx !== null) {
            $params[] = $link_id;
        }
        $sql = "SELECT id, post_id, user_id, guest_author_id, shared_link_id, content, title, images, comments, position_x, position_y, element_path, assigned_to, is_collapsed, is_completed, is_done, priority, device
                 FROM $table_name WHERE post_id = %d AND ((is_completed = 0 AND is_done = 0) OR id = %d)" . $where_guest . " ORDER BY updated_at DESC";
        $notes = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    } else {
        $params = array($post_id);
        if ($guest_ctx !== null) {
            $params[] = $link_id;
        }
        $sql = "SELECT id, post_id, user_id, guest_author_id, shared_link_id, content, title, images, comments, position_x, position_y, element_path, assigned_to, is_collapsed, is_completed, is_done, priority, device
                 FROM $table_name WHERE post_id = %d AND is_completed = 0 AND is_done = 0" . $where_guest . " ORDER BY updated_at DESC";
        $notes = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    } // end non-view-only branch

    if ($notes) {
        // Decrypt sensitive fields before returning
        foreach ($notes as &$n) {
            $n['content'] = sticky_comment_decrypt($n['content']);
            $n['element_path'] = sticky_comment_decrypt($n['element_path']);
            $n['assigned_to'] = sticky_comment_decrypt($n['assigned_to']);
            if (isset($n['title'])) { $n['title'] = sticky_comment_decrypt($n['title']); }
            // Images: decrypt and also resolve thumbnail URLs for convenience
            $image_ids = array();
            if (isset($n['images']) && $n['images'] !== null && $n['images'] !== '') {
                $images_json = sticky_comment_decrypt($n['images']);
                $decoded = json_decode($images_json, true);
                if (is_array($decoded)) {
                    $image_ids = array_values(array_filter(array_map('intval', $decoded)));
                }
            }
            $n['images'] = json_encode($image_ids);
            $urls = array();
            if (!empty($image_ids)) {
                foreach ($image_ids as $iid) {
                    // Resolve full-size URL so modal can show true size up to viewport
                    $full = wp_get_attachment_image_src($iid, 'full');
                    if ($full && is_array($full) && isset($full[0])) {
                        $urls[] = esc_url($full[0]);
                    } else {
                        $fallback = wp_get_attachment_url($iid);
                        if ($fallback) {
                            $urls[] = esc_url($fallback);
                        }
                    }
                }
            }
            $n['image_urls'] = $urls;

            // Comments: decrypt, enrich with first_name/email, then pass as JSON string
            if (isset($n['comments']) && $n['comments'] !== null && $n['comments'] !== '') {
                $dec = sticky_comment_decrypt($n['comments']);
                $arr = json_decode($dec, true);
                if (is_array($arr)) {
                    foreach ($arr as &$c) {
                        $uid = isset($c['user_id']) ? intval($c['user_id']) : 0;
                        // Ensure first_name present
                        if (!isset($c['first_name']) || (is_string($c['first_name']) && trim($c['first_name']) === '')) {
                            if ($uid > 0) {
                                $fn = get_user_meta($uid, 'first_name', true);
                                if (is_string($fn)) { $c['first_name'] = $fn; }
                            }
                        }
                        // Ensure user_email present
                        if (!isset($c['user_email']) || (is_string($c['user_email']) && trim($c['user_email']) === '')) {
                            if ($uid > 0) {
                                $u = get_userdata($uid);
                                if ($u && isset($u->user_email)) { $c['user_email'] = $u->user_email; }
                            }
                        }
                    }
                    unset($c);
                    $n['comments'] = wp_json_encode($arr);
                } else {
                    $n['comments'] = '[]';
                }
            } else {
                $n['comments'] = '[]';
            }
        }
        unset($n);
        wp_send_json_success($notes);
    } else {
        wp_send_json_error('No sticky notes found for this post.');
    }
}

// Add or append a comment to a sticky note
add_action('wp_ajax_sticky_add_comment', 'sticky_add_comment');
function sticky_add_comment() {
    // Basic checks
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sticky_comment_nonce')) {
        wp_send_json_error(array('message' => 'Invalid nonce'));
    }
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'User must be logged in'));
    }
    $min_cap = get_option('sticky_comment_settings_min_cap', 'manage_options');
    if (!current_user_can($min_cap)) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
    }

    $note_id = isset($_POST['note_id']) ? intval($_POST['note_id']) : 0;
    $content = isset($_POST['content']) ? sanitize_textarea_field($_POST['content']) : '';
    $content = trim($content);
    if ($note_id <= 0 || $content === '') {
        wp_send_json_error(array('message' => 'Invalid input'));
    }
    if (strlen($content) > 500) {
        $content = substr($content, 0, 500);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'sticky_notes';
    if (!sticky_comment_ensure_table_exists()) {
        wp_send_json_error(array('message' => 'Database table is not available'));
    }

    // Load current comments
    $row = $wpdb->get_row($wpdb->prepare("SELECT comments FROM $table_name WHERE id = %d", $note_id));
    if (!$row) {
        wp_send_json_error(array('message' => 'Note not found'));
    }
    $existing = array();
    if ($row->comments !== null && $row->comments !== '') {
        $dec = sticky_comment_decrypt($row->comments);
        $arr = json_decode($dec, true);
        if (is_array($arr)) { $existing = $arr; }
    }

    $current_user = wp_get_current_user();
    $new_comment = array(
        'user_id' => get_current_user_id(),
        'user_email' => $current_user->user_email,
        'first_name' => get_user_meta(get_current_user_id(), 'first_name', true),
        'content' => $content,
        'created_at' => current_time('mysql'),
    );
    $existing[] = $new_comment;
    $json = wp_json_encode($existing);

    $updated = $wpdb->update(
        $table_name,
        array(
            'comments' => sticky_comment_encrypt($json),
            'updated_at' => current_time('mysql'),
        ),
        array('id' => $note_id)
    );
    if ($updated === false) {
        wp_send_json_error(array('message' => 'Failed to add comment'));
    }

    wp_send_json_success(array(
        'count' => count($existing),
        'latest' => $new_comment,
    ));
}

// Allow users to delete their own comments (or users with min capability)
add_action('wp_ajax_sticky_delete_comment', 'sticky_delete_comment');
function sticky_delete_comment() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sticky_comment_nonce')) {
        wp_send_json_error(array('message' => 'Invalid nonce'));
    }
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'User must be logged in'));
    }

    $note_id = isset($_POST['note_id']) ? intval($_POST['note_id']) : 0;
    $index   = isset($_POST['index']) ? intval($_POST['index']) : -1;
    if ($note_id <= 0 || $index < 0) {
        wp_send_json_error(array('message' => 'Invalid input'));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'sticky_notes';
    if (!sticky_comment_ensure_table_exists()) {
        wp_send_json_error(array('message' => 'Database table is not available'));
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT comments FROM $table_name WHERE id = %d", $note_id));
    if (!$row) {
        wp_send_json_error(array('message' => 'Note not found'));
    }

    $existing = array();
    if ($row->comments !== null && $row->comments !== '') {
        $dec = sticky_comment_decrypt($row->comments);
        $arr = json_decode($dec, true);
        if (is_array($arr)) { $existing = $arr; }
    }

    if (!is_array($existing) || !array_key_exists($index, $existing)) {
        wp_send_json_error(array('message' => 'Comment not found'));
    }

    $comment = $existing[$index];
    $current_user_id = get_current_user_id();
    $min_cap = get_option('sticky_comment_settings_min_cap', 'manage_options');
    $can_delete_any = current_user_can($min_cap);
    $is_owner = intval(isset($comment['user_id']) ? $comment['user_id'] : 0) === intval($current_user_id);

    if (!$can_delete_any && !$is_owner) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
    }

    array_splice($existing, $index, 1);
    $json = wp_json_encode($existing);

    $updated = $wpdb->update(
        $table_name,
        array(
            'comments' => sticky_comment_encrypt($json),
            'updated_at' => current_time('mysql'),
        ),
        array('id' => $note_id)
    );
    if ($updated === false) {
        wp_send_json_error(array('message' => 'Failed to delete comment'));
    }

    wp_send_json_success(array(
        'count' => count($existing),
    ));
}

// AJAX user search for assignee suggestions
add_action('wp_ajax_search_sticky_users', 'sticky_comment_search_users');
function sticky_comment_search_users() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sticky_comment_nonce')) {
        wp_send_json_error('Invalid nonce');
    }
    if (!is_user_logged_in() || !current_user_can('list_users')) {
        wp_send_json_error('Insufficient permissions');
    }
    $query = isset($_POST['q']) ? sanitize_text_field($_POST['q']) : '';
    if (strlen($query) < 2) {
        wp_send_json_success([]);
    }
    $users = get_users([
        'search' => '*' . esc_attr($query) . '*',
        'search_columns' => ['user_login', 'user_nicename', 'display_name', 'user_email'],
        'number' => 10,
        'fields' => ['ID', 'display_name', 'user_login', 'user_email'],
        'orderby' => 'display_name',
        'order' => 'ASC',
        // Standard roles that can access the admin dashboard
        'role__in' => ['administrator','editor','author','contributor','subscriber'],
    ]);
    $results = array_map(function($u) {
        return [
            'id' => $u->ID,
            'email' => $u->user_email,
            'login' => $u->user_login,
            'display' => $u->display_name,
        ];
    }, $users);
    wp_send_json_success($results);
}


// Delete sticky note AJAX handler
add_action('wp_ajax_delete_sticky_note', 'delete_sticky_note');
add_action('wp_ajax_nopriv_delete_sticky_note', 'delete_sticky_note');
function delete_sticky_note() {
    // Check throttling first
    if (!sticky_comment_check_throttle('delete_note', 10, 60)) { // Stricter limit for deletions
        wp_send_json_error(array(
            'message' => __('Too many delete requests. Please wait a moment before trying again.', 'sticky-comment'),
            'retry_after' => 60
        ));
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sticky_comment_nonce')) {
        wp_send_json_error('Invalid nonce');
    }
    $guest_ctx = null;
    if (!is_user_logged_in()) {
        if (!function_exists('sticky_comment_get_guest_context_from_request')) {
            wp_send_json_error('User must be logged in');
        }
        $guest_ctx = sticky_comment_get_guest_context_from_request();
        if ($guest_ctx === null) {
            wp_send_json_error('User must be logged in');
        }
    }
    global $wpdb;
    $table_name = $wpdb->prefix . 'sticky_notes';
    if (!sticky_comment_ensure_table_exists()) {
        wp_send_json_error('Database table is not available');
    }
    $note_id = isset($_POST['note_id']) ? intval($_POST['note_id']) : 0;
    $user_id = get_current_user_id();

    if ($note_id > 0) {
        $min_cap = get_option('sticky_comment_settings_min_cap', 'manage_options');

        if ($guest_ctx !== null) {
            $note_row = $wpdb->get_row($wpdb->prepare(
                "SELECT images FROM $table_name WHERE id = %d AND guest_author_id = %s AND shared_link_id = %d",
                $note_id,
                $guest_ctx['guest_id'],
                $guest_ctx['link_id']
            ));
            $deleted = $note_row ? $wpdb->delete($table_name, array(
                'id' => $note_id,
                'guest_author_id' => $guest_ctx['guest_id'],
                'shared_link_id' => $guest_ctx['link_id'],
            )) : false;
        } elseif (current_user_can($min_cap)) {
            $note_row = $wpdb->get_row($wpdb->prepare("SELECT images FROM $table_name WHERE id = %d", $note_id));
            $deleted = $wpdb->delete($table_name, array('id' => $note_id));
        } else {
            $note_row = $wpdb->get_row($wpdb->prepare("SELECT images FROM $table_name WHERE id = %d AND user_id = %d", $note_id, $user_id));
            $deleted = $wpdb->delete($table_name, array('id' => $note_id, 'user_id' => $user_id));
        }

        if ($deleted) {
            // Best-effort: delete associated media attachments if any
            if ($note_row && isset($note_row->images) && $note_row->images !== null && $note_row->images !== '') {
                $decoded_ids = json_decode(sticky_comment_decrypt($note_row->images), true);
                if (is_array($decoded_ids)) {
                    foreach ($decoded_ids as $aid) {
                        $aid = intval($aid);
                        if ($aid > 0) {
                            // Force delete from media library
                            wp_delete_attachment($aid, true);
                        }
                    }
                }
            }
            wp_send_json_success('Sticky note deleted');
        } else {
            wp_send_json_error('Delete failed');
        }
    } else {
        wp_send_json_error('Invalid note ID');
    }
}

// Upload image for sticky note (to Media Library)
add_action('wp_ajax_sticky_upload_note_image', 'sticky_upload_note_image');
function sticky_upload_note_image() {
    // Throttle uploads a bit
    if (!sticky_comment_check_throttle('upload_image', 12, 60)) {
        wp_send_json_error(array('message' => __('Too many uploads. Please wait a moment.', 'sticky-comment')));
    }
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sticky_comment_nonce')) {
        wp_send_json_error(array('message' => 'Invalid nonce'));
    }
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'User must be logged in'));
    }
    $min_cap = get_option('sticky_comment_settings_min_cap', 'manage_options');
    if (!current_user_can($min_cap)) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
    }

    if (!isset($_FILES['file'])) {
        wp_send_json_error(array('message' => 'No file uploaded'));
    }

    // Load media libs
    if (!function_exists('media_handle_upload')) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
    }

    // Attach to no post (0). We only need ID to store
    $attachment_id = media_handle_upload('file', 0);
    if (is_wp_error($attachment_id)) {
        wp_send_json_error(array('message' => $attachment_id->get_error_message()));
    }

    $thumb = wp_get_attachment_image_src($attachment_id, 'thumbnail');
    // Use full-size URL for accurate display in modal
    $full = wp_get_attachment_image_src($attachment_id, 'full');
    $resp = array(
        'id' => (int)$attachment_id,
        'thumb_url' => $thumb && isset($thumb[0]) ? esc_url($thumb[0]) : esc_url(wp_get_attachment_url($attachment_id)),
        'url' => $full && isset($full[0]) ? esc_url($full[0]) : esc_url(wp_get_attachment_url($attachment_id)),
    );
    wp_send_json_success($resp);
}

// Delete a single uploaded image from the Media Library
add_action('wp_ajax_sticky_delete_note_image', 'sticky_delete_note_image');
function sticky_delete_note_image() {
    // Throttle deletes slightly
    if (!sticky_comment_check_throttle('delete_image', 12, 60)) {
        wp_send_json_error(array('message' => __('Too many delete requests. Please wait a moment.', 'sticky-comment')));
    }
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sticky_comment_nonce')) {
        wp_send_json_error(array('message' => 'Invalid nonce'));
    }
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'User must be logged in'));
    }
    $min_cap = get_option('sticky_comment_settings_min_cap', 'manage_options');
    if (!current_user_can($min_cap)) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
    }
    $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
    if ($attachment_id <= 0) {
        wp_send_json_error(array('message' => 'Invalid attachment ID'));
    }
    $deleted = wp_delete_attachment($attachment_id, true);
    if ($deleted) {
        wp_send_json_success(array('deleted' => (int)$attachment_id));
    } else {
        wp_send_json_error(array('message' => 'Failed to delete attachment'));
    }
}

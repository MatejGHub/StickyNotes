<?php

add_action('admin_menu', 'sticky_comments_plugin');

function sticky_comments_plugin() {
    // Dynamic capability (default administrators only)
    $min_cap = get_option('sticky_comment_settings_min_cap', 'manage_options');

    add_menu_page(
        'Sticky Comments',         // Page title
        'Sticky Comments',          // Menu title
        $min_cap,                  // Capability
        'sticky-comments',          // Menu slug
        'sticky_comments_page',    // Callback function
        'dashicons-welcome-write-blog', // Dashicon for notes
        80                           // Position near the bottom
    );
}

// Add Screen Options to toggle column visibility for the Sticky Comments admin page
add_action('current_screen', 'sticky_comments_screen_options');
function sticky_comments_screen_options($screen) {
    if (!isset($screen->id) || $screen->id !== 'toplevel_page_sticky-comments') {
        return;
    }
    // Ensure the Screen Options tab is visible
    add_filter('screen_options_show_screen', '__return_true');
    // Append our custom settings UI
    add_filter('screen_settings', 'sticky_comments_screen_settings', 10, 2);
}

// Add a convenient Admin Bar link on the frontend to open the Sticky Notes dashboard
add_action('admin_bar_menu', 'sticky_comments_adminbar_link', 100);
function sticky_comments_adminbar_link($wp_admin_bar) {
    // Only show on the frontend for logged-in users
    if (is_admin()) return;
    if (!is_user_logged_in()) return;

    // Respect the plugin's capability setting so the link matches access
    $min_cap = get_option('sticky_comment_settings_min_cap', 'manage_options');
    if (!current_user_can($min_cap)) return;

    $wp_admin_bar->add_node(array(
        'id'    => 'sticky-comments',
        'title' => '<span class="ab-icon dashicons dashicons-welcome-write-blog"></span><span class="ab-label">Sticky Notes</span>',
        'href'  => admin_url('admin.php?page=sticky-comments#sticky-notes'),
        'meta'  => array(
            'class' => 'sticky-comments-adminbar-item',
            'title' => 'Open Sticky Notes Dashboard',
        ),
    ));
}

function sticky_comments_screen_settings($settings, $screen) {
    if (!isset($screen->id) || $screen->id !== 'toplevel_page_sticky-comments') {
        return $settings;
    }
    // Use a global option for persistence across all users
    $hidden = get_option('sticky_comments_hidden_columns', array('created'));
    if (!is_array($hidden)) {
        $hidden = array('created');
    }
    $allowed = array('author', 'content', 'images', 'comments', 'created', 'priority');
    $labels  = array(
        'author'  => __('Author'),
        'content' => __('Content'),
        'images'  => __('Images'),
        'comments'=> __('Comments'),
        'created' => __('Created'),
        'priority'=> __('Priority'),
    );

    $html  = '<fieldset class="screen-options">';
    $html .= '<legend>' . esc_html__('Sticky Notes Columns') . '</legend>';
    $html .= '<p>' . esc_html__('Show on screen:') . '</p>';
    $html .= '<div class="metabox-prefs">';
    foreach ($allowed as $col) {
        $checked = in_array($col, $hidden, true) ? '' : ' checked="checked"';
        // Use our own AJAX-based system
        $html   .= '<label><input class="sticky-column-toggle" id="sticky-column-' . esc_attr($col) . '" type="checkbox" data-column="' . esc_attr($col) . '" value="' . esc_attr($col) . '"' . $checked . ' /> ' . esc_html($labels[$col]) . '</label> ';
    }
    // Hidden field retained for compatibility with Apply button flow
    $html .= '<input type="hidden" name="sticky_columns_submitted" value="1" />';
    $html .= '</div>';
    $html .= '</fieldset>';

    return $settings . $html;
}

// Handle saving Screen Options for the Sticky Comments admin page
add_action('load-toplevel_page_sticky-comments', 'sticky_comments_save_screen_options');
function sticky_comments_save_screen_options() {
    // This is now handled by AJAX, but keeping for Apply button compatibility
    if (!isset($_POST['sticky_columns_submitted'])) {
        return;
    }
    
    // Verify nonce used by the Screen Options form
    $nonce_valid = false;
    if (isset($_POST['screenoptionnonce']) && wp_verify_nonce($_POST['screenoptionnonce'], 'screen-options-nonce')) {
        $nonce_valid = true;
    }
    if (!$nonce_valid && isset($_POST['screen-options-nonce']) && wp_verify_nonce($_POST['screen-options-nonce'], 'screen-options-nonce')) {
        $nonce_valid = true;
    }
    if (!$nonce_valid) {
        return;
    }

    $allowed = array('author', 'content', 'images', 'comments', 'created', 'priority');
    $visible = array();
    if (isset($_POST['hide-column'])) {
        $visible = (array) $_POST['hide-column'];
    } elseif (isset($_POST['sticky_columns'])) {
        $visible = (array) $_POST['sticky_columns'];
    }
    $visible = array_values(array_intersect($allowed, array_map('sanitize_text_field', $visible)));
    $hidden  = array_values(array_diff($allowed, $visible));
    
    update_option('sticky_comments_hidden_columns', $hidden);
}

// AJAX handler for our custom column toggles
add_action('wp_ajax_sticky_toggle_column', 'sticky_comments_toggle_column_ajax');
function sticky_comments_toggle_column_ajax() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'sticky_column_toggle')) {
        wp_die('Security check failed');
    }
    
    $column = sanitize_text_field($_POST['column']);
    $visible = $_POST['visible'] === 'true';

    $allowed = array('author', 'content', 'images', 'comments', 'created', 'priority');
    if (!in_array($column, $allowed)) {
        wp_die('Invalid column');
    }
    
    // Get current hidden columns
    $hidden = get_option('sticky_comments_hidden_columns', array());
    if (!is_array($hidden)) {
        $hidden = array();
    }
    
    // Toggle visibility
    if ($visible) {
        // Remove from hidden list (make visible)
        $hidden = array_diff($hidden, array($column));
    } else {
        // Add to hidden list (hide)
        if (!in_array($column, $hidden)) {
            $hidden[] = $column;
        }
    }
    
    // Save updated hidden columns
    update_option('sticky_comments_hidden_columns', array_values($hidden));
    
    wp_send_json_success(array(
        'column' => $column,
        'visible' => $visible,
        'hidden_columns' => get_option('sticky_comments_hidden_columns')
    ));
}

function sticky_comments_page() {
    // Check dynamic capability and optional specific admin user restriction
    $min_cap = get_option('sticky_comment_settings_min_cap', 'manage_options');
    $allowed_admin_user = intval(get_option('sticky_comment_settings_admin_user', 0));

    if (!current_user_can($min_cap)) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    if ($allowed_admin_user && get_current_user_id() !== $allowed_admin_user) {
        wp_die(__('You are not allowed to access these settings.'));
    }

    // Get selected palette and create theme configuration
    // Check if a new palette was just submitted via POST (for immediate theme update after save)
    $palette = isset($_POST['sticky_palette']) && wp_verify_nonce($_POST['settings_nonce'] ?? '', 'sticky_settings_action')
        ? sanitize_text_field($_POST['sticky_palette'])
        : get_option('sticky_comment_palette', 'purple');

    // Get active tab from URL parameter or default to 'data'
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'data';
    if (!in_array($active_tab, array('data', 'settings', 'links'))) {
        $active_tab = 'data';
    }

    // Theme configuration for dashboard
    $themes = array(
        'purple' => array(
            'primary' => 'linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%)',
            'primary_hover' => 'linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%)',
            'primary_shadow' => 'rgba(102, 126, 234, 0.3)',
            'primary_focus' => 'rgba(102, 126, 234, 0.1)',
            'primary_dark' => '#6d28d9'
        ),
        'sunset' => array(
            'primary' => 'linear-gradient(135deg, #f59e0b 0%, #ea580c 100%)',
            'primary_hover' => 'linear-gradient(135deg, #ea580c 0%, #dc2626 100%)',
            'primary_shadow' => 'rgba(234, 88, 12, 0.3)',
            'primary_focus' => 'rgba(234, 88, 12, 0.1)',
            'primary_dark' => '#dc2626'
        ),
        'aurora' => array(
            'primary' => 'linear-gradient(135deg, #06b6d4 0%, #3b82f6 100%)',
            'primary_hover' => 'linear-gradient(135deg, #0891b2 0%, #2563eb 100%)',
            'primary_shadow' => 'rgba(6, 182, 212, 0.3)',
            'primary_focus' => 'rgba(6, 182, 212, 0.1)',
            'primary_dark' => '#0891b2'
        ),
        'midnight' => array(
            'primary' => 'linear-gradient(135deg, #1e293b 0%, #334155 100%)',
            'primary_hover' => 'linear-gradient(135deg, #334155 0%, #475569 100%)',
            'primary_shadow' => 'rgba(51, 65, 85, 0.3)',
            'primary_focus' => 'rgba(51, 65, 85, 0.1)',
            'primary_dark' => '#475569'
        ),
        'candy' => array(
            'primary' => 'linear-gradient(135deg, #ec4899 0%, #8b5cf6 100%)',
            'primary_hover' => 'linear-gradient(135deg, #db2777 0%, #7c3aed 100%)',
            'primary_shadow' => 'rgba(236, 72, 153, 0.3)',
            'primary_focus' => 'rgba(236, 72, 153, 0.1)',
            'primary_dark' => '#7c3aed'
        )
    );

    $theme = isset($themes[$palette]) ? $themes[$palette] : $themes['purple'];
    ?>
    <style>
    :root {
        --sticky-primary: <?php echo $theme['primary']; ?>;
        --sticky-primary-hover: <?php echo $theme['primary_hover']; ?>;
        --sticky-primary-shadow: <?php echo $theme['primary_shadow']; ?>;
        --sticky-primary-focus: <?php echo $theme['primary_focus']; ?>;
        --sticky-primary-dark: <?php echo $theme['primary_dark']; ?>;
    }
    </style>
    <div class="wrap" id="sticky-comments-admin">
      <h1>Sticky Notes</h1>
      <h2 class="nav-tab-wrapper">
        <a href="<?php echo esc_url(admin_url('admin.php?page=sticky-comments&tab=data')); ?>" class="nav-tab <?php echo $active_tab === 'data' ? 'nav-tab-active' : ''; ?>" id="tab-sticky-notes">Sticky Notes</a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=sticky-comments&tab=links')); ?>" class="nav-tab <?php echo $active_tab === 'links' ? 'nav-tab-active' : ''; ?>" id="tab-links">Shared Links</a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=sticky-comments&tab=settings')); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>" id="tab-settings">Settings</a>
      </h2>
      <div id="tab-content-sticky-notes" class="tab-content tab-content-data <?php echo $active_tab === 'data' ? 'active' : ''; ?>">
        <?php render_sticky_notes_table(); ?>
      </div>
      <div id="tab-content-links" class="tab-content tab-content-links <?php echo $active_tab === 'links' ? 'active' : ''; ?>">
        <?php render_sticky_shared_links_tab(); ?>
      </div>
      <div id="tab-content-settings" class="tab-content tab-content-settings <?php echo $active_tab === 'settings' ? 'active' : ''; ?>">
        <?php render_sticky_notes_settings(); ?>
      </div>
    </div>
    <?php
}

// Helper: Render the Shared Links tab (create link form + table with copy/recopy)
function render_sticky_shared_links_tab() {
    global $wpdb;
    if (!function_exists('sticky_comment_ensure_shared_links_table_exists')) {
        echo '<p>' . esc_html__('Shared links feature is not available.', 'sticky-comment') . '</p>';
        return;
    }
    sticky_comment_ensure_shared_links_table_exists();
    $links_table = $wpdb->prefix . 'sticky_shared_links';

    if (isset($_POST['sticky_create_link_nonce']) && wp_verify_nonce($_POST['sticky_create_link_nonce'], 'sticky_create_shared_link')) {
        $name = isset($_POST['sticky_link_name']) ? sanitize_text_field($_POST['sticky_link_name']) : '';
        $name = substr(trim($name), 0, 255);
        if ($name === '') {
            $name = __('Shared link', 'sticky-comment');
        }
        $expiry = isset($_POST['sticky_link_expiry']) ? sanitize_text_field($_POST['sticky_link_expiry']) : 'never';
        $expires_at = null;
        if ($expiry === '1day') {
            $expires_at = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
        } elseif ($expiry === '7days') {
            $expires_at = gmdate('Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS);
        } elseif ($expiry === '30days') {
            $expires_at = gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS);
        }
        $token = bin2hex(random_bytes(16));
        $inserted = $wpdb->insert(
            $links_table,
            array(
                'token'      => $token,
                'name'       => $name,
                'expires_at' => $expires_at,
                'created_by' => get_current_user_id(),
            ),
            array('%s', '%s', '%s', '%d')
        );
        if ($inserted) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Shared link created. Copy the link below.', 'sticky-comment') . '</p></div>';
        }
    }

    if (isset($_POST['sticky_delete_link_nonce']) && wp_verify_nonce($_POST['sticky_delete_link_nonce'], 'sticky_delete_shared_link')) {
        $delete_id = isset($_POST['sticky_delete_link_id']) ? absint($_POST['sticky_delete_link_id']) : 0;
        if ($delete_id > 0) {
            $wpdb->delete($links_table, array('id' => $delete_id), array('%d'));
            echo '<div class="notice notice-success"><p>' . esc_html__('Shared link deleted.', 'sticky-comment') . '</p></div>';
        }
    }

    $links = $wpdb->get_results("SELECT id, token, name, expires_at, created_at FROM `{$links_table}` ORDER BY created_at DESC", ARRAY_A);
    $date_format = get_option('date_format');
    $time_format = get_option('time_format');
    $datetime_format = trim($date_format . ' ' . $time_format);
    ?>
    <div class="wrap" style="padding:16px;">
        <h2><?php esc_html_e('Create new shared link', 'sticky-comment'); ?></h2>
        <form method="post" action="" class="form-container" style="max-width:600px; margin-bottom:24px;">
            <?php wp_nonce_field('sticky_create_shared_link', 'sticky_create_link_nonce'); ?>
            <div class="form-row">
                <div class="form-label">
                    <label for="sticky_link_name"><?php esc_html_e('Name', 'sticky-comment'); ?></label>
                </div>
                <div class="form-field">
                    <input type="text" id="sticky_link_name" name="sticky_link_name" value="" placeholder="<?php esc_attr_e('Enter assigned name', 'sticky-comment'); ?>" maxlength="255" style="max-width:300px;" />
                </div>
            </div>
            <div class="form-row">
                <div class="form-label">
                    <label for="sticky_link_expiry"><?php esc_html_e('Expiration', 'sticky-comment'); ?></label>
                </div>
                <div class="form-field">
                    <select id="sticky_link_expiry" name="sticky_link_expiry">
                        <option value="never"><?php esc_html_e('Never', 'sticky-comment'); ?></option>
                        <option value="1day"><?php esc_html_e('1 day', 'sticky-comment'); ?></option>
                        <option value="7days"><?php esc_html_e('7 days', 'sticky-comment'); ?></option>
                        <option value="30days"><?php esc_html_e('30 days', 'sticky-comment'); ?></option>
                    </select>
                </div>
            </div>
            <p><button type="submit" class="button button-primary"><?php esc_html_e('Create link', 'sticky-comment'); ?></button></p>
        </form>

        <h2><?php esc_html_e('Shared links', 'sticky-comment'); ?></h2>
        <?php if (empty($links)) : ?>
            <p><?php esc_html_e('No shared links yet. Create one above.', 'sticky-comment'); ?></p>
        <?php else : ?>
            <table class="widefat fixed striped wp-list-table">
                <thead>
                    <tr>
                        <th colspan="2"><?php esc_html_e('Name', 'sticky-comment'); ?></th>
                        <th colspan="5"><?php esc_html_e('Generated link', 'sticky-comment'); ?></th>
                        <th colspan="2"><?php esc_html_e('Created', 'sticky-comment'); ?></th>
                        <th colspan="2"><?php esc_html_e('Expires', 'sticky-comment'); ?></th>
                        <th colspan="2"><?php esc_html_e('Actions', 'sticky-comment'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($links as $link) : ?>
                    <?php
                    $is_expired = !empty($link['expires_at']) && strtotime($link['expires_at']) < time();
                    $full_url = home_url('/?sticky_guest=' . $link['token']);
                    ?>
                    <tr <?php echo $is_expired ? 'style="opacity:0.6;"' : ''; ?>>
                        <td colspan="2"><?php echo esc_html($link['name']); ?></td>
                        <td colspan="5"><code style="word-break:break-all;"><?php echo esc_html($full_url); ?></code></td>
                        <td colspan="2"><?php echo esc_html(!empty($link['created_at']) ? date_i18n($datetime_format, strtotime($link['created_at'])) : '—'); ?></td>
                        <td colspan="2"><?php echo empty($link['expires_at']) ? esc_html__('Never', 'sticky-comment') : esc_html(date_i18n($datetime_format, strtotime($link['expires_at']))); ?></td>
                        <td colspan="2">
                            <button type="button" class="button button-small sticky-copy-link" data-url="<?php echo esc_attr($full_url); ?>"><?php esc_html_e('Copy link', 'sticky-comment'); ?></button>
                            <form method="post" action="" style="display:inline;">
                                <?php wp_nonce_field('sticky_delete_shared_link', 'sticky_delete_link_nonce'); ?>
                                <input type="hidden" name="sticky_delete_link_id" value="<?php echo esc_attr($link['id']); ?>">
                                <button type="submit" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete this link?', 'sticky-comment')); ?>');"><?php esc_html_e('Delete', 'sticky-comment'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.sticky-copy-link').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var url = this.getAttribute('data-url');
                if (url && typeof navigator.clipboard !== 'undefined' && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(function() {
                        btn.textContent = '<?php echo esc_js(__('Copied!', 'sticky-comment')); ?>';
                        setTimeout(function() { btn.textContent = '<?php echo esc_js(__('Copy link', 'sticky-comment')); ?>'; }, 2000);
                    });
                }
            });
        });
    });
    </script>
    <?php
}

// Helper: Render the notes table (move your table code here)
function render_sticky_notes_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'sticky_notes';

    sticky_comment_ensure_table_exists();

    $notes = $wpdb->get_results("SELECT * FROM $table_name ORDER BY (post_id = 0) DESC, post_id ASC, is_completed ASC, created_at DESC");
    // Decrypt sensitive fields for display
    if (!empty($notes)) {
        foreach ($notes as $n) {
            if (isset($n->content)) {
                $n->content = sticky_comment_decrypt($n->content);
            }
            if (isset($n->assigned_to)) {
                $n->assigned_to = sticky_comment_decrypt($n->assigned_to);
            }
            if (isset($n->element_path)) {
                $n->element_path = sticky_comment_decrypt($n->element_path);
            }
            if (isset($n->images) && $n->images !== null && $n->images !== '') {
                $img_dec = sticky_comment_decrypt($n->images);
                $ids = json_decode($img_dec, true);
                $valid_ids = array();
                if (is_array($ids)) {
                    foreach ($ids as $id) {
                        $id = intval($id);
                        // Check if the attachment actually exists
                        if ($id > 0 && wp_get_attachment_image_src($id, 'thumbnail')) {
                            $valid_ids[] = $id;
                        }
                    }
                }
                $n->image_ids = $valid_ids;
            } else {
                $n->image_ids = array();
            }
        }
    }

    echo '<div class="wrap" style="padding:10px;">';
    echo '<div class="all-sticky-notes-header">';
    echo '<h1>All Sticky Notes</h1>';
    echo '<p><a href="#" class="button button-primary" id="sticky-add-global-note">+ Add Global Sticky Note</a></p>';
    echo '</div>';

    if ($notes) {
        $link_names = array();
        $link_ids = array();
        foreach ($notes as $n) {
            if (!empty($n->shared_link_id) && (int) $n->shared_link_id > 0) {
                $link_ids[(int) $n->shared_link_id] = true;
            }
        }
        if (!empty($link_ids)) {
            $links_table = $wpdb->prefix . 'sticky_shared_links';
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $links_table)) === $links_table) {
                $ids = array_keys($link_ids);
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $rows = $wpdb->get_results($wpdb->prepare("SELECT id, name FROM `{$links_table}` WHERE id IN ($placeholders)", $ids), ARRAY_A);
                if ($rows) {
                    foreach ($rows as $r) {
                        $link_names[(int) $r['id']] = $r['name'];
                    }
                }
            }
        }

        $hidden_columns_global = get_option('sticky_comments_hidden_columns', array('created'));
        if (!is_array($hidden_columns_global)) { $hidden_columns_global = array('created'); }
        $hide_author  = in_array('author', $hidden_columns_global, true);
        $hide_content = in_array('content', $hidden_columns_global, true);
        $hide_images  = in_array('images', $hidden_columns_global, true);
        $hide_created = in_array('created', $hidden_columns_global, true);
        $hide_updated = in_array('updated', $hidden_columns_global, true);
        $hide_priority = in_array('priority', $hidden_columns_global, true);
        $current_post_id = null;
        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        $datetime_format = trim($date_format . ' ' . $time_format);
        foreach ($notes as $note) {
            // Treat notes without a valid WP post as Global (group under post_id 0)
            $group_post_id = get_post_status($note->post_id) ? $note->post_id : 0;
            if ($group_post_id !== $current_post_id) {
                if ($current_post_id !== null) {
                    echo '</tbody></table>';
                    echo '</div>'; // Close previous post-table-container
                }
                $current_post_id = $group_post_id;
                $is_global = intval($current_post_id) === 0;
                $title = $is_global ? 'Global' : get_the_title($current_post_id);
                if (!$title) { $title = 'Global'; }
                $post_slug = 'post-' . $current_post_id;
                $header_class = 'post-toggle-header' . ($is_global ? '' : ' collapsed');
                $container_class = 'post-table-container' . ($is_global ? '' : ' collapsed');
                echo '<h2 class="' . esc_attr($header_class) . '" data-post-id="' . esc_attr($current_post_id) . '" data-post-slug="' . esc_attr($post_slug) . '">';
                echo esc_html($title);
                echo '</h2>';
                echo '<div class="' . esc_attr($container_class) . '" data-post-id="' . esc_attr($current_post_id) . '">';
                echo '<table class="widefat fixed striped wp-list-table"><thead><tr>';
                echo '<th class="manage-column column-id" colspan="1">ID</th>';
                if (!$hide_author)  echo '<th class="manage-column column-author" colspan="2">Author</th>';
                if (!$hide_content) echo '<th class="manage-column column-content" colspan="4">Content</th>';
                if (!$hide_images)  echo '<th class="manage-column column-images" colspan="1">Images</th>';
                // Comments column header (toggleable via Screen Options)
                $hide_comments = in_array('comments', $hidden_columns_global, true);
                if (!$hide_comments) echo '<th style="width:6rem;" class="manage-column column-comments" colspan="3">Comments</th>';
                echo '<th class="manage-column column-assigned_to" colspan="2">Assigned To</th>';
                echo '<th class="manage-column column-completed" colspan="1">Completed</th>';
                if (!$hide_created) echo '<th class="manage-column column-created">Created</th>';
                if (!$hide_updated) echo '<th class="manage-column column-updated">Updated</th>';
                echo '<th class="manage-column column-device" colspan="1">Device</th>';
                echo '<th class="manage-column column-view" colspan="1">View</th>';
                if (!$hide_priority) echo '<th style="width:3.5rem;"class="manage-column column-priority" colspan="2">Priority</th>';
                echo '<th class="manage-column column-delete" colspan="1">Delete</th>';
                echo '</tr></thead><tbody>';
            }

            // Get permalink for the note's post
            $post_link = $is_global ? admin_url('admin.php?page=sticky-comments') : get_permalink($note->post_id);
            $note_link = esc_url(add_query_arg('sv', $note->id, $post_link));
            // Resolve username/display name (or Guest + link name)
            $user_display = 'Unknown';
            if (!empty($note->user_id) && (int) $note->user_id > 0) {
                $user = get_user_by('id', (int) $note->user_id);
                if ($user) {
                    $user_display = $user->display_name ? $user->display_name : $user->user_login;
                }
            } elseif (!empty($note->guest_author_id)) {
                $user_display = __('Guest', 'sticky-comment');
                $sid = isset($note->shared_link_id) ? (int) $note->shared_link_id : 0;
                if ($sid > 0 && isset($link_names[$sid])) {
                    $user_display .= ' (' . esc_html($link_names[$sid]) . ')';
                }
            }
            // Format dates
            $created_human = !empty($note->created_at) ? date_i18n($datetime_format, strtotime($note->created_at)) : '';
            $updated_human = !empty($note->updated_at) ? date_i18n($datetime_format, strtotime($note->updated_at)) : '';

            $row_class = intval($note->is_completed) || intval($note->is_done) ? 'sticky-row-completed' : '';
            echo '<tr class="' . $row_class . '" data-note-id="' . intval($note->id) . '">';
            echo '<td class="column-id" colspan="1">' . esc_html($note->id) . '</td>';
            if (!$hide_author)  echo '<td class="column-author" colspan="2">' . esc_html($user_display) . '</td>';
            if (!$hide_content) echo '<td class="column-content" colspan="4">' . esc_html($note->content) . '</td>';
            // Images column
            if (!$hide_images) {
                echo '<td class="column-images" colspan="1">';
                if (!empty($note->image_ids)) {
                    $display_count = min(6, count($note->image_ids));
                    $total_count = count($note->image_ids);

                    // Professional single image display with count indicator
                    $first_image_id = $note->image_ids[0];
                    $thumb_src = wp_get_attachment_image_src($first_image_id, array(100, 100));
                    $full_src = wp_get_attachment_image_src($first_image_id, 'full');

                    if ($thumb_src && isset($thumb_src[0])) {
                        $full_url = $full_src && isset($full_src[0]) ? $full_src[0] : $thumb_src[0];

                        echo '<div style="position:relative;display:inline-block;">';
                        echo '<img src="' . esc_url($thumb_src[0]) . '" alt="' . esc_attr(get_post_meta($first_image_id, '_wp_attachment_image_alt', true) ?: 'Sticky note image') . '" data-full-url="' . esc_url($full_url) . '" data-note-images="' . esc_attr(json_encode(array_map(function($img_id) {
                            $thumb_src = wp_get_attachment_image_src($img_id, array(150, 150));
                            $full_src = wp_get_attachment_image_src($img_id, 'full');
                            return array(
                                'thumb' => $thumb_src ? $thumb_src[0] : '',
                                'full' => $full_src ? $full_src[0] : '',
                                'alt' => get_post_meta($img_id, '_wp_attachment_image_alt', true) ?: 'Sticky note image'
                            );
                        }, $note->image_ids))) . '" class="sticky-image-preview" style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;box-shadow:0 2px 8px rgba(0,0,0,.12);transition:all 0.2s ease;cursor:pointer;" onmouseover="this.style.transform=\'scale(1.05)\';this.style.boxShadow=\'0 4px 12px rgba(0,0,0,.2)\'" onmouseout="this.style.transform=\'scale(1)\';this.style.boxShadow=\'0 2px 8px rgba(0,0,0,.12)\'" />';

                        // Add count indicator for multiple images
                        if ($total_count > 1) {
                            echo '<div style="position:absolute;top:-8px;right:-8px;min-width:20px;height:20px;border-radius:10px;background:#6366f1;border:2px solid white;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;color:white;box-shadow:0 2px 4px rgba(0,0,0,0.1);">';
                            echo '<span>' . $total_count . '</span>';
                            echo '</div>';
                        }
                        echo '</div>';
                    }

                    // Show count if there are more than 6 images
                    if ($total_count > 6) {
                        $remaining = $total_count - 6;
                        echo '<span style="background:#f3f4f6;color:#6b7280;padding:4px 8px;border-radius:4px;font-size:12px;margin-left:4px;">+' . $remaining . ' more</span>';
                    }
                    echo '</div>';
                } else {
                    echo '<span style="color:#94a3b8;font-style:italic;">No images</span>';
                }
                echo '</td>';
            }
            // Comments column body
            if (!$hide_comments) {
                echo '<td class="column-comments" colspan="3">';
                $comments_json = isset($note->comments) ? (string) $note->comments : '';
                $comments_arr = array();
                if ($comments_json !== '') {
                    $dec = sticky_comment_decrypt($comments_json);
                    $tmp = json_decode($dec, true);
                    if (is_array($tmp)) { $comments_arr = $tmp; }
                }
                $c_count = is_array($comments_arr) ? count($comments_arr) : 0;
                if ($c_count > 0) {
                    echo '<span>' . intval($c_count) . '</span>';
                } else {
                    echo '<span style="color:#94a3b8;font-style:italic;">—</span>';
                }
                echo '</td>';
            }
            // Display assigned to field as stored
            $assigned_display = isset($note->assigned_to) ? (string) $note->assigned_to : '';
            // If it's just an email, show it as-is
            // If it's @username, show as-is
            // If it's empty, show nothing
            echo '<td class="column-assigned_to" colspan="2">' . esc_html($assigned_display) . '</td>';
            echo '<td class="column-completed" colspan="1">' . (intval($note->is_completed) ? 'Yes' : 'No') . '</td>';
            if (!$hide_created) echo '<td class="column-created">' . esc_html($created_human) . '</td>';
            if (!$hide_updated) echo '<td class="column-updated">' . esc_html($updated_human) . '</td>';
            $device_raw = isset($note->device) ? (string) $note->device : '';
            $device_label = $device_raw !== '' ? ucfirst($device_raw) : 'Unknown';
            echo '<td class="column-device" colspan="1">' . esc_html($device_label) . '</td>';
            echo '<td class="column-view" colspan="1"><a href="' . $note_link . '" target="_blank" class="button button-small">View</a></td>';
            if (!$hide_priority) {
                $p = isset($note->priority) ? intval($note->priority) : 2;
                $dotClass = 'priority-dot-medium';
                if ($p === 1) { $dotClass = 'priority-dot-low'; }
                elseif ($p === 3) { $dotClass = 'priority-dot-high'; }
                echo '<td class="column-priority" colspan="2"><span class="sticky-priority-dot ' . esc_attr($dotClass) . '" title="Priority"></span></td>';
            }
            echo '<td class="column-delete" colspan="1"><button class="button button-small sticky-admin-delete" data-note-id="' . intval($note->id) . '">Delete</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>'; // Close last post-table-container
    } else {
        echo '<p>No sticky notes found.</p>';
    }

    echo '</div>';

}

// Inline admin script to handle AJAX deletion and column toggling
add_action('admin_footer', function() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'sticky-comments') return;
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      const adminContainer = document.getElementById('sticky-comments-admin');
      if (!adminContainer) return;

      // Initialize post toggles
      initializePostToggles();
      
      // Handle note deletion
      document.querySelectorAll('.sticky-admin-delete').forEach(function(btn){
        btn.addEventListener('click', function(e){
          e.preventDefault();
          const noteId = this.getAttribute('data-note-id');
          if (!noteId) return;
          if (!confirm('Delete this note?')) return;
          fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
              action: 'delete_sticky_note',
              note_id: noteId,
              nonce: '<?php echo wp_create_nonce('sticky_comment_nonce'); ?>'
            })
          })
          .then(r => r.json())
          .then(data => {
            if (data && data.success) {
              const tr = btn.closest('tr');
              if (tr) tr.remove();
            } else {
              alert(data && (data.error || data.data) ? (data.error || data.data) : 'Delete failed');
            }
          })
          .catch(() => alert('Delete failed'));
        });
      });
      
      // Handle column visibility toggles
      document.querySelectorAll('.sticky-column-toggle').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
          const column = this.getAttribute('data-column');
          const isVisible = this.checked;
          
          // Immediately toggle column visibility
          toggleColumnVisibility(column, isVisible);
          
          // Save to server via AJAX
          fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
              action: 'sticky_toggle_column',
              column: column,
              visible: isVisible,
              nonce: '<?php echo wp_create_nonce('sticky_column_toggle'); ?>'
            })
          })
          .then(r => r.json())
          .then(data => {
            if (!data.success) {
              console.error('Failed to save column visibility:', data);
              // Revert the visual change
              toggleColumnVisibility(column, !isVisible);
              this.checked = !isVisible;
            }
          })
          .catch(err => {
            console.error('AJAX error:', err);
            // Revert the visual change
            toggleColumnVisibility(column, !isVisible);
            this.checked = !isVisible;
          });
        });
      });
      
      function toggleColumnVisibility(column, isVisible) {
        const tables = document.querySelectorAll('.wp-list-table');
        tables.forEach(function(table) {
          const headers = table.querySelectorAll('.column-' + column);
          const cells = table.querySelectorAll('.column-' + column);
          
          headers.forEach(function(el) {
            if (isVisible) {
              el.classList.remove('sticky-hidden');
            } else {
              el.classList.add('sticky-hidden');
            }
          });
          cells.forEach(function(el) {
            if (isVisible) {
              el.classList.remove('sticky-hidden');
            } else {
              el.classList.add('sticky-hidden');
            }
          });
        });
      }

      // Handle tab switching (removed preventDefault to allow URL navigation)
      // Tab switching is now handled server-side based on URL parameters

      // Post table toggle functionality
      function initializePostToggles() {
        const toggleHeaders = document.querySelectorAll('.post-toggle-header');

        toggleHeaders.forEach(header => {
          const postId = header.dataset.postId;
          const tableContainer = document.querySelector(`.post-table-container[data-post-id="${postId}"]`);
          const storageKey = `sticky_post_toggle_${postId}`;

          // Load saved state from localStorage
          const isCollapsed = localStorage.getItem(storageKey) === 'true';
          if (!isCollapsed) {
            header.classList.remove('collapsed');
            tableContainer.classList.remove('collapsed');
          }

          // Add click handler
          header.addEventListener('click', function() {
            const isCurrentlyCollapsed = header.classList.contains('collapsed');

            if (isCurrentlyCollapsed) {
              // Expand
              header.classList.remove('collapsed');
              tableContainer.classList.remove('collapsed');
              localStorage.setItem(storageKey, 'false');
            } else {
              // Collapse
              header.classList.add('collapsed');
              tableContainer.classList.add('collapsed');
              localStorage.setItem(storageKey, 'true');
            }
          });
        });
      }

      // Image modal functionality
      let currentImageIndex = 0;
      let currentImages = [];

      // Remove existing modal if it exists to prevent conflicts
      const existingModal = document.getElementById('sticky-image-modal');
      if (existingModal) {
        existingModal.remove();
      }

      // Create fresh modal elements
      const modal = document.createElement('div');
      modal.id = 'sticky-image-modal';
      modal.innerHTML = `
        <div class="sticky-images-modal">
          <div class="sticky-images-modal-content">
            <div class="sticky-images-modal-actions">
              <button class="sticky-images-modal-close">&times;</button>
            </div>
            <button class="sticky-images-nav sticky-images-prev">&larr;</button>
            <button class="sticky-images-nav sticky-images-next">&rarr;</button>
            <img class="sticky-images-modal-image" src="" alt="" />
            <div class="sticky-modal-counter" style="text-align:center;margin-top:10px;color:#ffffff;font-size:14px;"></div>
          </div>
        </div>
      `;
      // Ensure modal is hidden initially
      modal.style.display = 'none';
      document.body.appendChild(modal);

      // Modal functionality
      function openImageModal(images, startIndex = 0) {
        currentImages = images;
        currentImageIndex = startIndex;
        updateModalImage();
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
      }

      function updateModalImage() {
        const image = currentImages[currentImageIndex];
        const modalImg = modal.querySelector('.sticky-images-modal-image');
        const counter = modal.querySelector('.sticky-modal-counter');

        modalImg.src = image.full;
        modalImg.alt = image.alt;
        counter.textContent = `${currentImageIndex + 1} of ${currentImages.length}`;

        // Show/hide navigation buttons
        const prevBtn = modal.querySelector('.sticky-images-prev');
        const nextBtn = modal.querySelector('.sticky-images-next');
        prevBtn.style.display = currentImages.length > 1 ? 'block' : 'none';
        nextBtn.style.display = currentImages.length > 1 ? 'block' : 'none';
      }

      function closeImageModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
      }

      function nextImage() {
        currentImageIndex = (currentImageIndex + 1) % currentImages.length;
        updateModalImage();
      }

      function prevImage() {
        currentImageIndex = (currentImageIndex - 1 + currentImages.length) % currentImages.length;
        updateModalImage();
      }

      // Only attach event listeners if modal was newly created
      if (!modal.getAttribute('data-initialized')) {
        // Event listeners
        modal.querySelector('.sticky-images-modal-close').addEventListener('click', closeImageModal);
        modal.querySelector('.sticky-images-modal').addEventListener('click', function(e) {
          if (e.target === this) {
            closeImageModal();
          }
        });
        modal.querySelector('.sticky-images-next').addEventListener('click', nextImage);
        modal.querySelector('.sticky-images-prev').addEventListener('click', prevImage);

        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
          if (modal.style.display === 'flex') {
            if (e.key === 'Escape') {
              closeImageModal();
            } else if (e.key === 'ArrowRight' && currentImages.length > 1) {
              nextImage();
            } else if (e.key === 'ArrowLeft' && currentImages.length > 1) {
              prevImage();
            }
          }
        });

        // Handle image clicks
        document.addEventListener('click', function(e) {
          if (e.target.classList.contains('sticky-image-preview')) {
            e.preventDefault();
            const imagesData = JSON.parse(e.target.getAttribute('data-note-images'));
            const clickedImageUrl = e.target.getAttribute('data-full-url');
            const startIndex = imagesData.findIndex(img => img.full === clickedImageUrl);
            openImageModal(imagesData, startIndex >= 0 ? startIndex : 0);
          }
        });

        // Mark as initialized
        modal.setAttribute('data-initialized', 'true');
      }

      // Palette preview functionality
      const paletteSelect = document.getElementById('sticky_palette');
      if (paletteSelect) {
        // Define all available themes
        const themes = {
          'purple': {
            'primary': 'linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%)',
            'primary_hover': 'linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%)',
            'primary_shadow': 'rgba(102, 126, 234, 0.3)',
            'primary_focus': 'rgba(102, 126, 234, 0.1)',
            'primary_dark': '#6d28d9'
          },
          'sunset': {
            'primary': 'linear-gradient(135deg, #f59e0b 0%, #ea580c 100%)',
            'primary_hover': 'linear-gradient(135deg, #ea580c 0%, #dc2626 100%)',
            'primary_shadow': 'rgba(234, 88, 12, 0.3)',
            'primary_focus': 'rgba(234, 88, 12, 0.1)',
            'primary_dark': '#dc2626'
          },
          'aurora': {
            'primary': 'linear-gradient(135deg, #06b6d4 0%, #3b82f6 100%)',
            'primary_hover': 'linear-gradient(135deg, #0891b2 0%, #2563eb 100%)',
            'primary_shadow': 'rgba(6, 182, 212, 0.3)',
            'primary_focus': 'rgba(6, 182, 212, 0.1)',
            'primary_dark': '#0891b2'
          },
          'midnight': {
            'primary': 'linear-gradient(135deg, #1e293b 0%, #334155 100%)',
            'primary_hover': 'linear-gradient(135deg, #334155 0%, #475569 100%)',
            'primary_shadow': 'rgba(51, 65, 85, 0.3)',
            'primary_focus': 'rgba(51, 65, 85, 0.1)',
            'primary_dark': '#475569'
          },
          'candy': {
            'primary': 'linear-gradient(135deg, #ec4899 0%, #8b5cf6 100%)',
            'primary_hover': 'linear-gradient(135deg, #db2777 0%, #7c3aed 100%)',
            'primary_shadow': 'rgba(236, 72, 153, 0.3)',
            'primary_focus': 'rgba(236, 72, 153, 0.1)',
            'primary_dark': '#7c3aed'
          }
        };

        // Function to apply theme
        function applyTheme(palette) {
          const theme = themes[palette] || themes['purple'];
          const root = document.documentElement;

          root.style.setProperty('--sticky-primary', theme.primary);
          root.style.setProperty('--sticky-primary-hover', theme.primary_hover);
          root.style.setProperty('--sticky-primary-shadow', theme.primary_shadow);
          root.style.setProperty('--sticky-primary-focus', theme.primary_focus);
          root.style.setProperty('--sticky-primary-dark', theme.primary_dark);
        }

        // Apply theme on change (for the hidden input)
        if (paletteSelect) {
          paletteSelect.addEventListener('change', function() {
            const selectedPalette = this.value;
            applyTheme(selectedPalette);
          });
        }

        // Handle palette dropdown
        const paletteTrigger = document.getElementById('palette-trigger');
        const paletteMenu = document.getElementById('palette-menu');
        const paletteOptions = document.querySelectorAll('.palette-option');
        const stickyPaletteInput = document.getElementById('sticky_palette');
        const currentPalettePreview = document.querySelector('.current-palette-preview');
        const currentPaletteName = document.querySelector('.current-palette-name');
        const dropdownArrow = document.querySelector('.dropdown-arrow');

        let isDropdownOpen = false;

        // Function to update current palette display
        function updateCurrentPalette(palette, name, desc, gradient) {
          if (currentPalettePreview) {
            currentPalettePreview.style.background = gradient;
          }
          if (currentPaletteName) {
            currentPaletteName.textContent = name;
          }
        }

        // Initialize current palette display
        const currentPaletteOption = document.querySelector('.palette-option.selected');
        if (currentPaletteOption) {
          const palette = currentPaletteOption.dataset.palette;
          const name = currentPaletteOption.dataset.name;
          const desc = currentPaletteOption.dataset.desc;
          const gradient = currentPaletteOption.dataset.gradient;
          updateCurrentPalette(palette, name, desc, gradient);
        }

        // Toggle dropdown
        if (paletteTrigger) {
          paletteTrigger.addEventListener('click', function(e) {
            e.stopPropagation();
            isDropdownOpen = !isDropdownOpen;

            if (isDropdownOpen) {
              paletteMenu.style.display = 'block';
              paletteTrigger.style.borderRadius = '8px 8px 0 0';
              paletteTrigger.style.borderBottomColor = 'transparent';
              if (dropdownArrow) {
                dropdownArrow.style.transform = 'rotate(180deg)';
              }
            } else {
              paletteMenu.style.display = 'none';
              paletteTrigger.style.borderRadius = '8px';
              paletteTrigger.style.borderBottomColor = '#e2e8f0';
              if (dropdownArrow) {
                dropdownArrow.style.transform = 'rotate(0deg)';
              }
            }
          });
        }

        // Handle palette selection
        paletteOptions.forEach(option => {
          option.addEventListener('click', function() {
            const palette = this.dataset.palette;
            const name = this.dataset.name;
            const desc = this.dataset.desc;
            const gradient = this.dataset.gradient;

            // Remove selected class from all options
            paletteOptions.forEach(opt => {
              opt.classList.remove('selected');
              opt.style.borderColor = '#e2e8f0';
            });

            // Add selected class to clicked option
            this.classList.add('selected');

            // Update border color based on palette
            const borderColors = {
              'purple': '#8b5cf6',
              'sunset': '#f59e0b',
              'aurora': '#06b6d4',
              'midnight': '#1e293b',
              'candy': '#ec4899'
            };
            this.style.borderColor = borderColors[palette] || '#e2e8f0';

            // Update hidden input
            if (stickyPaletteInput) {
              stickyPaletteInput.value = palette;
            }

            // Update current display
            updateCurrentPalette(palette, name, desc, gradient);

            // Apply theme immediately
            applyTheme(palette);

            // Close dropdown
            isDropdownOpen = false;
            paletteMenu.style.display = 'none';
            paletteTrigger.style.borderRadius = '8px';
            paletteTrigger.style.borderBottomColor = '#e2e8f0';
            if (dropdownArrow) {
              dropdownArrow.style.transform = 'rotate(0deg)';
            }
          });
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
          if (isDropdownOpen && !paletteTrigger.contains(e.target) && !paletteMenu.contains(e.target)) {
            isDropdownOpen = false;
            paletteMenu.style.display = 'none';
            paletteTrigger.style.borderRadius = '8px';
            paletteTrigger.style.borderBottomColor = '#e2e8f0';
            if (dropdownArrow) {
              dropdownArrow.style.transform = 'rotate(0deg)';
            }
          }
        });
      }
    });
    </script>
    <?php
});

// Helper: Render the settings tab
function render_sticky_notes_settings() {
    // Save settings
    if (isset($_POST['submit']) && wp_verify_nonce($_POST['settings_nonce'], 'sticky_settings_action')) {
        update_option('sticky_comment_max_notes', intval($_POST['max_notes']));
        // New: settings page access restriction
        update_option('sticky_comment_settings_min_cap', sanitize_text_field($_POST['settings_min_cap'] ?? 'manage_options'));
        update_option('sticky_comment_settings_admin_user', intval($_POST['settings_admin_user'] ?? 0));
        // Save selected color palette
        update_option('sticky_comment_palette', sanitize_text_field($_POST['sticky_palette'] ?? 'purple'));
        // Save throttling settings
        update_option('sticky_comment_throttle_limit', intval($_POST['throttle_limit'] ?? 20));
        // Save notification email
        update_option('sticky_comment_notification_email', sanitize_email($_POST['notification_email'] ?? ''));
        echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
    }

    // Defaults: admin only by default
    $max_notes = get_option('sticky_comment_max_notes', 10);
    $settings_min_cap = get_option('sticky_comment_settings_min_cap', 'manage_options');
    $settings_admin_user = intval(get_option('sticky_comment_settings_admin_user', 0));
    $selected_palette = get_option('sticky_comment_palette', 'purple');
    $throttle_limit = get_option('sticky_comment_throttle_limit', 20);
    $notification_email = get_option('sticky_comment_notification_email', '');

    // Prepare administrators list for dropdown
    $admins = get_users(array(
        'role'    => 'administrator',
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => array('ID', 'display_name', 'user_login')
    ));
    ?>
    <h3 style="margin-bottom:0px; padding-bottom:10px;">Settings</h3>
    <form method="post" action="">
        <?php wp_nonce_field('sticky_settings_action', 'settings_nonce'); ?>
        <div class="form-container">
            <div class="form-row">
                <div class="form-label">
                    <label for="max_notes">Maximum Notes Per Page</label>
                </div>
                <div class="form-field">
                    <input type="number" id="max_notes" name="max_notes" value="<?php echo esc_attr($max_notes); ?>" min="1" max="50" />
                    <p class="description">Maximum number of sticky notes allowed per post (1-50)</p>
                </div>
            </div>

            <div class="form-row">
                <div class="form-label">
                    <label for="sticky_palette">Color Theme</label>
                </div>
                <div class="form-field">
                    <input type="hidden" id="sticky_palette" name="sticky_palette" value="<?php echo esc_attr($selected_palette); ?>" />
                    <div class="palette-dropdown-container" style="position: relative;">
                        <div class="palette-dropdown-trigger" id="palette-trigger" style="border: 2px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; font-size: 14px; transition: all 0.2s ease; background: #ffffff; cursor: pointer; position: relative; display: flex; align-items: center; justify-content: space-between; width: 100%; max-width: 275px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div class="current-palette-preview" style="width: 24px; height: 24px; border-radius: 4px; background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); box-shadow: 0 1px 4px rgba(0,0,0,0.1);"></div>
                                <span class="current-palette-name" style="font-size: 14px; color: #374151;">Purple</span>
                            </div>
                            <div class="dropdown-arrow" style="font-size: 12px; color: #64748b; transition: transform 0.2s ease;">▼</div>
                        </div>

                        <div class="palette-dropdown-menu" id="palette-menu" style="position: absolute; top: 100%; left: 0; right: 0; background: #ffffff; border: 2px solid #e2e8f0; border-top: none; border-radius: 0 0 8px 8px; box-shadow: 0 8px 32px rgba(0,0,0,0.15); z-index: 1000; display: none; padding: 16px;">
                            <div class="palette-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
                                <div class="palette-option <?php echo $selected_palette === 'purple' ? 'selected' : ''; ?>" data-palette="purple" data-name="Purple" data-desc="Default" data-gradient="linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%)" style="cursor: pointer; padding: 12px; border-radius: 6px; border: 2px solid <?php echo $selected_palette === 'purple' ? '#8b5cf6' : '#e2e8f0'; ?>; text-align: center; transition: all 0.2s ease; background: #ffffff;">
                                    <div class="palette-preview" style="width: 100%; height: 40px; border-radius: 4px; background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); margin-bottom: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"></div>
                                    <div class="palette-name" style="font-size: 12px; font-weight: 600; color: #374151;">Purple</div>
                                </div>

                                <div class="palette-option <?php echo $selected_palette === 'sunset' ? 'selected' : ''; ?>" data-palette="sunset" data-name="Sunset" data-desc="Warm" data-gradient="linear-gradient(135deg, #f59e0b 0%, #ea580c 100%)" style="cursor: pointer; padding: 12px; border-radius: 6px; border: 2px solid <?php echo $selected_palette === 'sunset' ? '#f59e0b' : '#e2e8f0'; ?>; text-align: center; transition: all 0.2s ease; background: #ffffff;">
                                    <div class="palette-preview" style="width: 100%; height: 40px; border-radius: 4px; background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%); margin-bottom: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"></div>
                                    <div class="palette-name" style="font-size: 12px; font-weight: 600; color: #374151;">Sunset</div>
                                </div>

                                <div class="palette-option <?php echo $selected_palette === 'aurora' ? 'selected' : ''; ?>" data-palette="aurora" data-name="Aurora" data-desc="Cool" data-gradient="linear-gradient(135deg, #06b6d4 0%, #3b82f6 100%)" style="cursor: pointer; padding: 12px; border-radius: 6px; border: 2px solid <?php echo $selected_palette === 'aurora' ? '#06b6d4' : '#e2e8f0'; ?>; text-align: center; transition: all 0.2s ease; background: #ffffff;">
                                    <div class="palette-preview" style="width: 100%; height: 40px; border-radius: 4px; background: linear-gradient(135deg, #06b6d4 0%, #3b82f6 100%); margin-bottom: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"></div>
                                    <div class="palette-name" style="font-size: 12px; font-weight: 600; color: #374151;">Aurora</div>
                                </div>

                                <div class="palette-option <?php echo $selected_palette === 'midnight' ? 'selected' : ''; ?>" data-palette="midnight" data-name="Midnight" data-desc="Dark" data-gradient="linear-gradient(135deg, #1e293b 0%, #334155 100%)" style="cursor: pointer; padding: 12px; border-radius: 6px; border: 2px solid <?php echo $selected_palette === 'midnight' ? '#1e293b' : '#e2e8f0'; ?>; text-align: center; transition: all 0.2s ease; background: #ffffff;">
                                    <div class="palette-preview" style="width: 100%; height: 40px; border-radius: 4px; background: linear-gradient(135deg, #1e293b 0%, #334155 100%); margin-bottom: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"></div>
                                    <div class="palette-name" style="font-size: 12px; font-weight: 600; color: #374151;">Midnight</div>
                                </div>

                                <div class="palette-option <?php echo $selected_palette === 'candy' ? 'selected' : ''; ?>" data-palette="candy" data-name="Candy" data-desc="Bright" data-gradient="linear-gradient(135deg, #ec4899 0%, #8b5cf6 100%)" style="cursor: pointer; padding: 12px; border-radius: 6px; border: 2px solid <?php echo $selected_palette === 'candy' ? '#ec4899' : '#e2e8f0'; ?>; text-align: center; transition: all 0.2s ease; background: #ffffff;">
                                    <div class="palette-preview" style="width: 100%; height: 40px; border-radius: 4px; background: linear-gradient(135deg, #ec4899 0%, #8b5cf6 100%); margin-bottom: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"></div>
                                    <div class="palette-name" style="font-size: 12px; font-weight: 600; color: #374151;">Candy</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p class="description">Set color theme for the sticky notes.</p>
                </div>
            </div>

            <div class="form-row">
                <div class="form-label">
                    <label for="throttle_limit">Limit requests</label>
                </div>
                <div class="form-field">
                    <input type="number" id="throttle_limit" name="throttle_limit" value="<?php echo esc_attr($throttle_limit); ?>" min="5" max="100" />
                    <p class="description">Maximum number of requests per user per minute (5-100).</p>
                </div>
            </div>

            <div class="form-row">
                <div class="form-label">
                    <label for="notification_email">Notification Email</label>
                </div>
                <div class="form-field">
                    <input type="email" id="notification_email" name="notification_email" value="<?php echo esc_attr($notification_email); ?>" placeholder="email@example.com" style="max-width:300px;" />
                    <p class="description">Email address to receive notifications when a sticky note is saved.</p>
                </div>
            </div>

            <div class="form-row">
                <div class="form-label">
                    <label for="settings_min_cap">Who can edit plugin settings?</label>
                </div>
                <div class="form-field">
                    <select id="settings_min_cap" name="settings_min_cap">
                        <option value="manage_options" <?php selected($settings_min_cap, 'manage_options'); ?>>Administrators (default)</option>
                        <option value="edit_others_posts" <?php selected($settings_min_cap, 'edit_others_posts'); ?>>Editors and above</option>
                        <option value="publish_posts" <?php selected($settings_min_cap, 'publish_posts'); ?>>Authors and above</option>
                    </select>
                    <p class="description">Default is administrators only.</p>
                </div>
            </div>

            <div class="form-row">
                <div class="form-label">
                    <label for="settings_admin_user">Restrict settings to a specific administrator</label>
                </div>
                <div class="form-field">
                    <select id="settings_admin_user" name="settings_admin_user">
                        <option value="0" <?php selected($settings_admin_user, 0); ?>>— No restriction —</option>
                        <?php foreach ($admins as $admin) : ?>
                            <option value="<?php echo esc_attr($admin->ID); ?>" <?php selected($settings_admin_user, $admin->ID); ?>>
                                <?php echo esc_html($admin->display_name . ' (@' . $admin->user_login . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Optional: limit settings access to one admin user.</p>
                </div>
            </div>
        </div>
        <?php submit_button(); ?>
    </form>
    <?php
}

<?php

if (!defined('STICKY_COMMENT_DB_VERSION')) {
    define('STICKY_COMMENT_DB_VERSION', '1.5');
}

/**
 * Encrypt plaintext for safe storage.
 * Uses AES-256-CBC with a key derived from WordPress auth salts.
 */
function sticky_comment_encrypt($plaintext) {
    if ($plaintext === '' || $plaintext === null) {
        return '';
    }
    if (!is_string($plaintext)) {
        $plaintext = strval($plaintext);
    }
    if (!function_exists('openssl_encrypt')) {
        return $plaintext; // Fallback: store as-is if OpenSSL unavailable
    }
    $keyMaterial = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '');
    $key = hash('sha256', $keyMaterial, true);
    $iv = openssl_random_pseudo_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        return $plaintext;
    }
    return base64_encode($iv . $cipher);
}

/**
 * Decrypt ciphertext stored by sticky_comment_encrypt.
 */
function sticky_comment_decrypt($ciphertext) {
    if ($ciphertext === '' || $ciphertext === null) {
        return '';
    }
    if (!is_string($ciphertext)) {
        $ciphertext = strval($ciphertext);
    }
    if (!function_exists('openssl_decrypt')) {
        return $ciphertext;
    }
    $decoded = base64_decode($ciphertext, true);
    if ($decoded === false || strlen($decoded) < 17) {
        return $ciphertext;
    }
    $iv = substr($decoded, 0, 16);
    $raw = substr($decoded, 16);
    $keyMaterial = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '');
    $key = hash('sha256', $keyMaterial, true);
    $plain = openssl_decrypt($raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return ($plain === false) ? $ciphertext : $plain;
}

function sticky_comment_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'sticky_notes';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        post_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        content TEXT NOT NULL,
        title TEXT NULL,
        images TEXT NULL,
        comments TEXT NULL,
        position_x INT NOT NULL,
        position_y INT NOT NULL,
        element_path TEXT NOT NULL,
        assigned_to TEXT NULL,
        is_collapsed TINYINT(1) NOT NULL DEFAULT 0,
        is_completed TINYINT(1) NOT NULL DEFAULT 0,
        is_done TINYINT(1) NOT NULL DEFAULT 0,
        priority TINYINT(1) NOT NULL DEFAULT 2,
        device VARCHAR(20) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_post_id (post_id),
        INDEX idx_user_id (user_id),
        INDEX idx_post_user (post_id, user_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    update_option('sticky_comment_db_version', STICKY_COMMENT_DB_VERSION);
}

function sticky_comment_ensure_table_exists() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'sticky_notes';
    $table_name_esc = esc_sql($table_name);
    
    $table_exists = $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $table_name)
    ) == $table_name;
    
    if (!$table_exists) {
        sticky_comment_create_table();
        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table_name)
        ) == $table_name;
        return (bool) $table_exists;
    }

    $column = $wpdb->get_row(
        $wpdb->prepare("SHOW COLUMNS FROM `$table_name_esc` LIKE %s", 'element_path')
    );
    if ($column && stripos($column->Type, 'varchar') !== false) {
        $wpdb->query("ALTER TABLE `$table_name_esc` MODIFY element_path TEXT NOT NULL");
    }

    $assigned_col = $wpdb->get_row(
        $wpdb->prepare("SHOW COLUMNS FROM `$table_name_esc` LIKE %s", 'assigned_to')
    );
    if (!$assigned_col) {
        $wpdb->query("ALTER TABLE `$table_name_esc` ADD COLUMN assigned_to TEXT NULL AFTER element_path");
    } else {
        if (stripos($assigned_col->Type, 'text') === false) {
            $wpdb->query("ALTER TABLE `$table_name_esc` MODIFY assigned_to TEXT NULL");
        }
    }

    $completed_col = $wpdb->get_row(
        $wpdb->prepare("SHOW COLUMNS FROM `$table_name_esc` LIKE %s", 'is_completed')
    );
    if (!$completed_col) {
        $wpdb->query("ALTER TABLE `$table_name_esc` ADD COLUMN is_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_collapsed");
    }
    $is_done_col = $wpdb->get_row(
        $wpdb->prepare("SHOW COLUMNS FROM `$table_name_esc` LIKE %s", 'is_done')
    );
    if (!$is_done_col) {
        $wpdb->query("ALTER TABLE `$table_name_esc` ADD COLUMN is_done TINYINT(1) NOT NULL DEFAULT 0 AFTER is_completed");
        $wpdb->query("UPDATE `$table_name_esc` SET is_done = 1 WHERE is_completed = 1");
    }

    // Add priority column if missing (1=Low, 2=Medium, 3=High)
    $priority_col = $wpdb->get_row(
        $wpdb->prepare("SHOW COLUMNS FROM `$table_name_esc` LIKE %s", 'priority')
    );
    if (!$priority_col) {
        $wpdb->query("ALTER TABLE `$table_name_esc` ADD COLUMN priority TINYINT(1) NOT NULL DEFAULT 2 AFTER is_done");
    }

    $wpdb->query("UPDATE `$table_name_esc` SET assigned_to = NULL WHERE assigned_to = '0'");

    $title_col = $wpdb->get_row(
        $wpdb->prepare("SHOW COLUMNS FROM `$table_name_esc` LIKE %s", 'title')
    );
    if (!$title_col) {
        $wpdb->query("ALTER TABLE `$table_name_esc` ADD COLUMN title TEXT NULL AFTER content");
    }

    // Add images column if missing (stores encrypted JSON array of attachment IDs)
    $images_col = $wpdb->get_row(
        $wpdb->prepare("SHOW COLUMNS FROM `$table_name_esc` LIKE %s", 'images')
    );
    if (!$images_col) {
        $wpdb->query("ALTER TABLE `$table_name_esc` ADD COLUMN images TEXT NULL AFTER title");
    }

    // Add comments column if missing (stores encrypted JSON array of comment objects)
    $comments_col = $wpdb->get_row(
        $wpdb->prepare("SHOW COLUMNS FROM `$table_name_esc` LIKE %s", 'comments')
    );
    if (!$comments_col) {
        $wpdb->query("ALTER TABLE `$table_name_esc` ADD COLUMN comments TEXT NULL AFTER images");
    }

    // Add device column if missing (stores device type: mobile/tablet/desktop)
    $device_col = $wpdb->get_row(
        $wpdb->prepare("SHOW COLUMNS FROM `$table_name_esc` LIKE %s", 'device')
    );
    if (!$device_col) {
        $wpdb->query("ALTER TABLE `$table_name_esc` ADD COLUMN device VARCHAR(20) NULL AFTER priority");
    }

    sticky_comment_ensure_shared_links_table_exists();
    $guest_author_col = $wpdb->get_row(
        $wpdb->prepare("SHOW COLUMNS FROM `$table_name_esc` LIKE %s", 'guest_author_id')
    );
    if (!$guest_author_col) {
        $wpdb->query("ALTER TABLE `$table_name_esc` ADD COLUMN guest_author_id VARCHAR(64) NULL AFTER user_id");
    }
    $shared_link_col = $wpdb->get_row(
        $wpdb->prepare("SHOW COLUMNS FROM `$table_name_esc` LIKE %s", 'shared_link_id')
    );
    if (!$shared_link_col) {
        $wpdb->query("ALTER TABLE `$table_name_esc` ADD COLUMN shared_link_id BIGINT UNSIGNED NULL AFTER guest_author_id");
    }

    update_option('sticky_comment_db_version', STICKY_COMMENT_DB_VERSION);
    return true;
}

function sticky_comment_ensure_shared_links_table_exists() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'sticky_shared_links';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;
    if ($exists) {
        return true;
    }
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(64) NOT NULL,
        name VARCHAR(255) NOT NULL,
        expires_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY token (token),
        KEY expires_at (expires_at)
    ) $charset_collate;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    return true;
}

/**
 * Return guest context if the request has a valid shared-link token (from GET or cookie).
 * Returns array with token, guest_id, link_id, expires_at or null.
 */
function sticky_comment_get_guest_context() {
    if (is_user_logged_in()) {
        return null;
    }
    sticky_comment_ensure_table_exists();
    global $wpdb;
    $links_table = $wpdb->prefix . 'sticky_shared_links';
    $token = '';
    if (!empty($_GET['sticky_guest']) && is_string($_GET['sticky_guest'])) {
        $token = preg_replace('/[^a-f0-9]/', '', strtolower(sanitize_text_field($_GET['sticky_guest'])));
        if (strlen($token) !== 32) {
            $token = '';
        }
    }
    if ($token === '' && !empty($_COOKIE['sticky_guest_token']) && is_string($_COOKIE['sticky_guest_token'])) {
        $token = preg_replace('/[^a-f0-9]/', '', strtolower(sanitize_text_field($_COOKIE['sticky_guest_token'])));
        if (strlen($token) !== 32) {
            $token = '';
        }
    }
    if ($token === '') {
        return null;
    }
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, expires_at FROM `{$links_table}` WHERE token = %s LIMIT 1",
        $token
    ), ARRAY_A);
    if (!$row) {
        return null;
    }
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
        return null;
    }
    $guest_id = '';
    if (!empty($_COOKIE['sticky_guest_id']) && is_string($_COOKIE['sticky_guest_id'])) {
        $guest_id = preg_replace('/[^a-f0-9]/', '', strtolower(sanitize_text_field($_COOKIE['sticky_guest_id'])));
        if (strlen($guest_id) !== 32) {
            $guest_id = '';
        }
    }
    if ($guest_id === '' && function_exists('sticky_comment_get_guest_id_this_request')) {
        $guest_id = sticky_comment_get_guest_id_this_request();
    }
    return array(
        'token'      => $token,
        'guest_id'   => $guest_id,
        'link_id'    => (int) $row['id'],
        'expires_at' => isset($row['expires_at']) ? $row['expires_at'] : null,
    );
}

/**
 * Guest context from current request (cookie or POST). For use in AJAX handlers.
 */
function sticky_comment_get_guest_context_from_request() {
    $ctx = sticky_comment_get_guest_context();
    if ($ctx !== null && $ctx['guest_id'] !== '') {
        return $ctx;
    }
    if ($ctx !== null) {
        return $ctx;
    }
    $token = '';
    $guest_id = '';
    if (!empty($_POST['guest_token']) && is_string($_POST['guest_token'])) {
        $token = preg_replace('/[^a-f0-9]/', '', strtolower(sanitize_text_field($_POST['guest_token'])));
    }
    if (!empty($_POST['guest_id']) && is_string($_POST['guest_id'])) {
        $guest_id = preg_replace('/[^a-f0-9]/', '', strtolower(sanitize_text_field($_POST['guest_id'])));
    }
    if (strlen($token) !== 32 || strlen($guest_id) !== 32) {
        return null;
    }
    global $wpdb;
    $links_table = $wpdb->prefix . 'sticky_shared_links';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, expires_at FROM `{$links_table}` WHERE token = %s LIMIT 1",
        $token
    ), ARRAY_A);
    if (!$row || (!empty($row['expires_at']) && strtotime($row['expires_at']) < time())) {
        return null;
    }
    return array(
        'token'      => $token,
        'guest_id'   => $guest_id,
        'link_id'    => (int) $row['id'],
        'expires_at' => isset($row['expires_at']) ? $row['expires_at'] : null,
    );
}

$main_plugin_file = dirname(dirname(__DIR__)) . '/sticky-comment.php';
register_activation_hook($main_plugin_file, 'sticky_comment_create_table');

function sticky_comment_enqueue_scripts() {
    if (is_admin()) {
        return;
    }
    $is_guest = false;
    $guest_ctx = null;
    if (is_user_logged_in()) {
    } elseif (function_exists('sticky_comment_get_guest_context')) {
        $guest_ctx = sticky_comment_get_guest_context();
        if ($guest_ctx !== null) {
            $is_guest = true;
        }
    }
    if (!is_user_logged_in() && !$is_guest) {
        return;
    }

    $plugin_url = plugin_dir_url(dirname(dirname(__DIR__)) . '/sticky-comment.php');
    $plugin_dir = plugin_dir_path(dirname(dirname(__DIR__)) . '/sticky-comment.php');

    $palette = get_option('sticky_comment_palette', 'purple');
        $themes = array(
            'purple' => array(
                'primary' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                'primary_hover' => 'linear-gradient(135deg, #764ba2 0%, #6d28d9 100%)',
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

        $inject_version = filemtime($plugin_dir . 'includes/js/inject-sticky-comment.js');
        $fetch_version = filemtime($plugin_dir . 'includes/js/fetch-sticky-comment.js');
        $remove_version = filemtime($plugin_dir . 'includes/js/remove-sticky-comment.js');
        $create_version = filemtime($plugin_dir . 'includes/js/create-sticky-comment.js');
        $feedback_version = filemtime($plugin_dir . 'includes/js/user-feedback.js');
        $css_version = filemtime($plugin_dir . 'sticky-comment.css');

        wp_enqueue_script(
            'sticky-comment-script',
            $plugin_url . 'includes/js/inject-sticky-comment.js',
            array('jquery'),
            $inject_version,
            true
        );

        wp_enqueue_script(
            'fetch-sticky-comment-script',
            $plugin_url . 'includes/js/fetch-sticky-comment.js',
            array('jquery', 'sticky-comment-script'),
            $fetch_version,
            true
        );

        wp_enqueue_script(
            'remove-sticky-comment-script',
            $plugin_url . 'includes/js/remove-sticky-comment.js',
            array('jquery', 'sticky-comment-script'),
            $remove_version,
            true
        );

        wp_enqueue_script(
            'create-sticky-comment-script',
            $plugin_url . 'includes/js/create-sticky-comment.js',
            array('jquery', 'sticky-comment-script'),
            $create_version,
            true
        );

        wp_enqueue_script(
            'sticky-user-feedback-script',
            $plugin_url . 'includes/js/user-feedback.js',
            array(),
            $feedback_version,
            true
        );

    // Use queried object ID for reliability across themes/contexts
    $post_id = (int) get_queried_object_id();
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $uri  = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $page_url = esc_url_raw($scheme . '://' . $host . $uri);
    $max_notes = (int) get_option('sticky_comment_max_notes', 10);

    if ($is_guest) {
        $base = array(
            'ajax_url'          => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce('sticky_comment_nonce'),
            'post_id'           => $post_id,
            'page_url'          => $page_url,
            'max_notes'         => $max_notes,
            'can_edit'          => 1,
            'is_guest'          => 1,
            'guest_token'       => $guest_ctx['token'],
            'guest_id'          => $guest_ctx['guest_id'],
            'shared_link_id'    => $guest_ctx['link_id'],
            'current_user_id'   => 0,
            'current_user_display' => '',
            'current_user_email'   => '',
            'current_user_first_name' => '',
        );
    } else {
        $min_cap = get_option('sticky_comment_settings_min_cap', 'manage_options');
        $can_edit = current_user_can($min_cap);
        $base = array(
            'ajax_url'          => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce('sticky_comment_nonce'),
            'post_id'           => $post_id,
            'page_url'          => $page_url,
            'max_notes'         => $max_notes,
            'can_edit'          => $can_edit ? 1 : 0,
            'current_user_id'   => get_current_user_id(),
            'current_user_display' => wp_get_current_user() ? wp_get_current_user()->display_name : '',
            'current_user_email'   => wp_get_current_user() ? wp_get_current_user()->user_email : '',
            'current_user_first_name' => get_user_meta(get_current_user_id(), 'first_name', true),
        );
    }

    wp_localize_script('sticky-comment-script', 'my_ajax_object', $base);
    wp_localize_script('fetch-sticky-comment-script', 'my_ajax_object', $base);
    wp_localize_script('remove-sticky-comment-script', 'my_ajax_object', $base);
    wp_localize_script('create-sticky-comment-script', 'my_ajax_object', $base);

    wp_enqueue_style(
            'sticky-comment-style',
            $plugin_url . 'sticky-comment.css',
            array(),
            $css_version
        );

        $theme_css = ":root {
            --sticky-primary: {$theme['primary']};
            --sticky-primary-hover: {$theme['primary_hover']};
            --sticky-primary-shadow: {$theme['primary_shadow']};
            --sticky-primary-focus: {$theme['primary_focus']};
            --sticky-primary-dark: {$theme['primary_dark']};
        }";

        wp_add_inline_style('sticky-comment-style', $theme_css);

        $palette = get_option('sticky_comment_palette', 'purple');
        $themes = array(
            'purple' => array(
                'header' => 'linear-gradient(135deg,#8b5cf6 0%, #7c3aed 50%, #6d28d9 100%)',
                'header_hover' => 'linear-gradient(135deg,#7c3aed 0%, #6d28d9 50%, #581c87 100%)',
                'bubble' => 'linear-gradient(135deg,#8b5cf6 0%, #7c3aed 100%)',
                'bubble_hover' => 'linear-gradient(135deg,#7c3aed 0%, #6d28d9 100%)',
                'action' => '#7c3aed'
            ),
            'blue' => array(
                'header' => 'linear-gradient(135deg,#3b82f6 0%, #2563eb 50%, #1e40af 100%)',
                'header_hover' => 'linear-gradient(135deg,#2563eb 0%, #1d4ed8 50%, #1e3a8a 100%)',
                'bubble' => 'linear-gradient(135deg,#3b82f6 0%, #1d4ed8 100%)',
                'bubble_hover' => 'linear-gradient(135deg,#1d4ed8 0%, #1e3a8a 100%)',
                'action' => '#2563eb'
            ),
            'orange' => array(
                'header' => 'linear-gradient(135deg,#fb923c 0%, #f97316 50%, #ea580c 100%)',
                'header_hover' => 'linear-gradient(135deg,#f97316 0%, #ea580c 50%, #c2410c 100%)',
                'bubble' => 'linear-gradient(135deg,#fb923c 0%, #ea580c 100%)',
                'bubble_hover' => 'linear-gradient(135deg,#ea580c 0%, #c2410c 100%)',
                'action' => '#ea580c'
            ),
            'slate' => array(
                'header' => 'linear-gradient(135deg,#64748b 0%, #475569 50%, #334155 100%)',
                'header_hover' => 'linear-gradient(135deg,#475569 0%, #334155 50%, #1e293b 100%)',
                'bubble' => 'linear-gradient(135deg,#64748b 0%, #475569 100%)',
                'bubble_hover' => 'linear-gradient(135deg,#475569 0%, #334155 100%)',
                'action' => '#475569'
            ),
            'sunset' => array(
                'header' => 'linear-gradient(135deg,#f59e0b 0%, #ea580c 50%, #dc2626 100%)',
                'header_hover' => 'linear-gradient(135deg,#ea580c 0%, #dc2626 50%, #b91c1c 100%)',
                'bubble' => 'linear-gradient(135deg,#f59e0b 0%, #dc2626 100%)',
                'bubble_hover' => 'linear-gradient(135deg,#ea580c 0%, #b91c1c 100%)',
                'action' => '#dc2626'
            ),
            'ocean' => array(
                'header' => 'linear-gradient(135deg,#0891b2 0%, #0369a1 50%, #0284c7 100%)',
                'header_hover' => 'linear-gradient(135deg,#0369a1 0%, #0284c7 50%, #0369a1 100%)',
                'bubble' => 'linear-gradient(135deg,#0891b2 0%, #0369a1 100%)',
                'bubble_hover' => 'linear-gradient(135deg,#0369a1 0%, #0284c7 100%)',
                'action' => '#0891b2'
            ),
            'aurora' => array(
                'header' => 'linear-gradient(135deg,#06b6d4 0%, #3b82f6 50%, #8b5cf6 100%)',
                'header_hover' => 'linear-gradient(135deg,#0891b2 0%, #2563eb 50%, #7c3aed 100%)',
                'bubble' => 'linear-gradient(135deg,#06b6d4 0%, #3b82f6 100%)',
                'bubble_hover' => 'linear-gradient(135deg,#0891b2 0%, #1d4ed8 100%)',
                'action' => '#0891b2'
            ),
            'midnight' => array(
                'header' => 'linear-gradient(135deg,#0f172a 0%, #1e293b 50%, #334155 100%)',
                'header_hover' => 'linear-gradient(135deg,#1e293b 0%, #334155 50%, #475569 100%)',
                'bubble' => 'linear-gradient(135deg,#1e293b 0%, #334155 100%)',
                'bubble_hover' => 'linear-gradient(135deg,#334155 0%, #475569 100%)',
                'action' => '#334155'
            ),
            'candy' => array(
                'header' => 'linear-gradient(135deg,#ec4899 0%, #d946ef 40%, #8b5cf6 100%)',
                'header_hover' => 'linear-gradient(135deg,#db2777 0%, #c026d3 40%, #7c3aed 100%)',
                'bubble' => 'linear-gradient(135deg,#ec4899 0%, #8b5cf6 100%)',
                'bubble_hover' => 'linear-gradient(135deg,#c026d3 0%, #7c3aed 100%)',
                'action' => '#c026d3'
            )
        );

        $theme = isset($themes[$palette]) ? $themes[$palette] : $themes['purple'];
        $header_gradient = $theme['header'];
        $header_hover_gradient = $theme['header_hover'];
        $bubble_gradient = $theme['bubble'];
        $bubble_hover_gradient = $theme['bubble_hover'];
        $action_color = $theme['action'];

        $custom_css = "
        .sticky-handle { background: {$header_gradient} !important; }
        .sticky-handle:hover { background: {$header_hover_gradient} !important; }
        .sticky-comment-save { background: {$header_gradient} !important; }
        .sticky-comment-save:hover { background: {$header_hover_gradient} !important; }
        .sticky-modal-header { background: {$header_gradient} !important; }
        .sticky-modal-assign-header { background: {$header_gradient} !important; }
        .sticky-assign-button { background: {$header_gradient} !important; }
        .sticky-assign-button:hover { background: {$header_hover_gradient} !important; }
        .sticky-bubble { background: {$bubble_gradient} !important; box-shadow: 0 8px 32px rgba(0,0,0,0.18), 0 4px 16px rgba(0,0,0,0.12) !important; }
        .sticky-bubble:hover { background: {$bubble_hover_gradient} !important; transform: scale(1.1) translateY(-4px) !important; box-shadow: 0 12px 40px rgba(0,0,0,0.25), 0 8px 24px rgba(0,0,0,0.15) !important; }
        .sticky-actions-item { color: {$action_color} !important; }
        .sticky-note-item-action { color: {$action_color} !important; }
        .sticky-loader { color: {$action_color} !important; }

        /* Theme-aware priority popover + choices */
        .sticky-priority-popup {
          border: 1px solid transparent !important;
          background: linear-gradient(rgba(255,255,255,0.94), rgba(255,255,255,0.94)) padding-box,
                      {$header_gradient} border-box !important;
        }
        .sticky-priority-choice:hover {
          border-color: {$action_color} !important;
          box-shadow: 0 2px 10px rgba(0,0,0,0.06) !important;
        }
        .sticky-priority-choice.is-active {
          border: 1px solid transparent !important;
          background: linear-gradient(#ffffff, #ffffff) padding-box,
                      {$header_gradient} border-box !important;
          box-shadow: 0 0 0 3px rgba(0,0,0,0.02), 0 6px 18px rgba(0,0,0,0.08) !important;
        }
        .sticky-priority-choice.is-active .sticky-priority-choice-label {
          color: {$action_color} !important;
        }

        /* Theme-aware row toggle and chevron */
        .sticky-row-toggle { color: {$action_color} !important; }
        .sticky-row-toggle:hover { color: {$action_color} !important; opacity: 0.9 !important; }
        .sticky-toggle-chevron { border-top-color: {$action_color} !important; }
        .sticky-list-row.open .sticky-row-toggle { color: {$action_color} !important; }
        .sticky-list-row.open .sticky-toggle-chevron { border-top-color: {$action_color} !important; }

        /* Character counter themed */
        .sticky-char-counter { color: {$action_color}; opacity: .85; }

        /* Theme textarea scrollbar thumb for WebKit and Firefox */
        .sticky-text { scrollbar-color: {$action_color}40 transparent !important; }
        .sticky-text::-webkit-scrollbar-thumb { background: {$action_color}66 !important; }
        .sticky-text::-webkit-scrollbar-thumb:hover { background: {$action_color}99 !important; }
        .sticky-assign-results { scrollbar-color: {$action_color}40 transparent !important; }
        .sticky-assign-results::-webkit-scrollbar-thumb { background: {$action_color}66 !important; }
        .sticky-assign-results::-webkit-scrollbar-thumb:hover { background: {$action_color}99 !important; }
        /* Expose theme color for runtime CSS consumers */
        :root { --sticky-action-bg: {$action_color}; }
        ";
    wp_add_inline_style('sticky-comment-style', $custom_css);
}

add_action('wp_enqueue_scripts', 'sticky_comment_enqueue_scripts');

function sticky_notes_admin_scripts($hook) {
    if ($hook !== 'toplevel_page_sticky-comments') return;
    $plugin_url = plugin_dir_url(dirname(dirname(__DIR__)) . '/sticky-comment.php');
    $plugin_dir = plugin_dir_path(dirname(dirname(__DIR__)) . '/sticky-comment.php');
    $admin_version = filemtime($plugin_dir . 'includes/js/admin-sticky-comment.js');
    // Frontend scripts needed to render the sticky note UI inside admin
    $inject_version = filemtime($plugin_dir . 'includes/js/inject-sticky-comment.js');
    $fetch_version = filemtime($plugin_dir . 'includes/js/fetch-sticky-comment.js');
    $remove_version = filemtime($plugin_dir . 'includes/js/remove-sticky-comment.js');
    $create_version = filemtime($plugin_dir . 'includes/js/create-sticky-comment.js');
    $feedback_version = filemtime($plugin_dir . 'includes/js/user-feedback.js');
    $css_version = filemtime($plugin_dir . 'sticky-comment.css');

    // Enqueue only what's needed to render and manage a single note in admin (avoid bubble UI)
    wp_enqueue_script(
        'remove-sticky-comment-script',
        $plugin_url . 'includes/js/remove-sticky-comment.js',
        array('jquery'),
        $remove_version,
        true
    );

    wp_enqueue_script(
        'create-sticky-comment-script',
        $plugin_url . 'includes/js/create-sticky-comment.js',
        array('jquery', 'remove-sticky-comment-script'),
        $create_version,
        true
    );

    wp_enqueue_script(
        'sticky-user-feedback-script',
        $plugin_url . 'includes/js/user-feedback.js',
        array(),
        $feedback_version,
        true
    );

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $uri  = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $page_url = esc_url_raw($scheme . '://' . $host . $uri);
    $post_id = 0; // Global/admin context
    $max_notes = (int) get_option('sticky_comment_max_notes', 10);
    $min_cap = get_option('sticky_comment_settings_min_cap', 'manage_options');
    $can_edit = current_user_can($min_cap) ? 1 : 0;

    foreach (array('remove-sticky-comment-script','create-sticky-comment-script') as $handle) {
        wp_localize_script($handle, 'my_ajax_object', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('sticky_comment_nonce'),
            'post_id'  => $post_id,
            'page_url' => $page_url,
            'max_notes'=> $max_notes,
            'can_edit' => $can_edit,
            'current_user_id' => get_current_user_id(),
            'current_user_login' => wp_get_current_user() ? wp_get_current_user()->user_login : '',
            'current_user_display' => wp_get_current_user() ? wp_get_current_user()->display_name : '',
        ));
    }

    wp_enqueue_script(
        'sticky-notes-admin-tabs',
        $plugin_url . 'includes/js/admin-sticky-comment.js',
        array('jquery','create-sticky-comment-script'),
        $admin_version,
        true
    );

    wp_enqueue_style(
        'sticky-comment-admin-style',
        $plugin_url . 'sticky-comment.css',
        array(),
        $css_version
    );

    $palette = get_option('sticky_comment_palette', 'purple');
    $themes = array(
        'purple' => array(
            'header' => 'linear-gradient(135deg,#8b5cf6 0%, #7c3aed 50%, #6d28d9 100%)',
            'header_hover' => 'linear-gradient(135deg,#7c3aed 0%, #6d28d9 50%, #581c87 100%)',
            'bubble' => 'linear-gradient(135deg,#8b5cf6 0%, #7c3aed 100%)',
            'bubble_hover' => 'linear-gradient(135deg,#7c3aed 0%, #6d28d9 100%)',
            'action' => '#7c3aed'
        ),
        'blue' => array(
            'header' => 'linear-gradient(135deg,#3b82f6 0%, #2563eb 50%, #1e40af 100%)',
            'header_hover' => 'linear-gradient(135deg,#2563eb 0%, #1d4ed8 50%, #1e3a8a 100%)',
            'bubble' => 'linear-gradient(135deg,#3b82f6 0%, #1d4ed8 100%)',
            'bubble_hover' => 'linear-gradient(135deg,#1d4ed8 0%, #1e3a8a 100%)',
            'action' => '#2563eb'
        ),
        'orange' => array(
            'header' => 'linear-gradient(135deg,#fb923c 0%, #f97316 50%, #ea580c 100%)',
            'header_hover' => 'linear-gradient(135deg,#f97316 0%, #ea580c 50%, #c2410c 100%)',
            'bubble' => 'linear-gradient(135deg,#fb923c 0%, #ea580c 100%)',
            'bubble_hover' => 'linear-gradient(135deg,#ea580c 0%, #c2410c 100%)',
            'action' => '#ea580c'
        ),
        'slate' => array(
            'header' => 'linear-gradient(135deg,#64748b 0%, #475569 50%, #334155 100%)',
            'header_hover' => 'linear-gradient(135deg,#475569 0%, #334155 50%, #1e293b 100%)',
            'bubble' => 'linear-gradient(135deg,#64748b 0%, #475569 100%)',
            'bubble_hover' => 'linear-gradient(135deg,#475569 0%, #334155 100%)',
            'action' => '#475569'
        ),
        'sunset' => array(
            'header' => 'linear-gradient(135deg,#f59e0b 0%, #ea580c 50%, #dc2626 100%)',
            'header_hover' => 'linear-gradient(135deg,#ea580c 0%, #dc2626 50%, #b91c1c 100%)',
            'bubble' => 'linear-gradient(135deg,#f59e0b 0%, #dc2626 100%)',
            'bubble_hover' => 'linear-gradient(135deg,#ea580c 0%, #b91c1c 100%)',
            'action' => '#dc2626'
        ),
        'ocean' => array(
            'header' => 'linear-gradient(135deg,#0891b2 0%, #0369a1 50%, #0284c7 100%)',
            'header_hover' => 'linear-gradient(135deg,#0369a1 0%, #0284c7 50%, #0369a1 100%)',
            'bubble' => 'linear-gradient(135deg,#0891b2 0%, #0369a1 100%)',
            'bubble_hover' => 'linear-gradient(135deg,#0369a1 0%, #0284c7 100%)',
            'action' => '#0891b2'
        ),
        'aurora' => array(
            'header' => 'linear-gradient(135deg,#06b6d4 0%, #3b82f6 50%, #8b5cf6 100%)',
            'header_hover' => 'linear-gradient(135deg,#0891b2 0%, #2563eb 50%, #7c3aed 100%)',
            'bubble' => 'linear-gradient(135deg,#06b6d4 0%, #3b82f6 100%)',
            'bubble_hover' => 'linear-gradient(135deg,#0891b2 0%, #1d4ed8 100%)',
            'action' => '#0891b2'
        ),
        'midnight' => array(
            'header' => 'linear-gradient(135deg,#0f172a 0%, #1e293b 50%, #334155 100%)',
            'header_hover' => 'linear-gradient(135deg,#1e293b 0%, #334155 50%, #475569 100%)',
            'bubble' => 'linear-gradient(135deg,#1e293b 0%, #334155 100%)',
            'bubble_hover' => 'linear-gradient(135deg,#334155 0%, #475569 100%)',
            'action' => '#334155'
        ),
        'candy' => array(
            'header' => 'linear-gradient(135deg,#ec4899 0%, #d946ef 40%, #8b5cf6 100%)',
            'header_hover' => 'linear-gradient(135deg,#db2777 0%, #c026d3 40%, #7c3aed 100%)',
            'bubble' => 'linear-gradient(135deg,#ec4899 0%, #8b5cf6 100%)',
            'bubble_hover' => 'linear-gradient(135deg,#c026d3 0%, #7c3aed 100%)',
            'action' => '#c026d3'
        )
    );

    $theme = isset($themes[$palette]) ? $themes[$palette] : $themes['purple'];
    $header_gradient = $theme['header'];
    $header_hover_gradient = $theme['header_hover'];

    $admin_custom_css = "
    /* Theme the sticky note components in admin using palette */
    .sticky-handle { background: {$header_gradient} !important; }
    .sticky-handle:hover { background: {$header_hover_gradient} !important; }
    .sticky-comment-save { background: {$header_gradient} !important; }
    .sticky-comment-save:hover { background: {$header_hover_gradient} !important; }
    .sticky-modal-header { background: {$header_gradient} !important; }
    .sticky-modal-assign-header { background: {$header_gradient} !important; }
    .sticky-assign-button { background: {$header_gradient} !important; }
    .sticky-assign-button:hover { background: {$header_hover_gradient} !important; }

    /* Priority popover accent in admin */
    .sticky-priority-popup {
      border: 1px solid transparent !important;
      background: linear-gradient(rgba(255,255,255,0.94), rgba(255,255,255,0.94)) padding-box,
                  {$header_gradient} border-box !important;
    }
    .sticky-priority-choice.is-active {
      border: 1px solid transparent !important;
      background: linear-gradient(#ffffff, #ffffff) padding-box,
                  {$header_gradient} border-box !important;
    }
    ";
    wp_add_inline_style('sticky-comment-admin-style', $admin_custom_css);
}
add_action('admin_enqueue_scripts', 'sticky_notes_admin_scripts');
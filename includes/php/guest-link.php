<?php

/**
 * Guest shared-link: set cookies when a non-logged-in user visits with ?sticky_guest=TOKEN.
 * Runs on init so cookies are available before enqueue.
 */

if (!defined('ABSPATH')) {
    exit;
}

function sticky_comment_guest_link_maybe_set_cookies()
{
    if (is_user_logged_in()) {
        return;
    }
    $token_raw = isset($_GET['sticky_guest']) && is_string($_GET['sticky_guest'])
        ? $_GET['sticky_guest']
        : '';
    $token = $token_raw !== '' ? preg_replace('/[^a-f0-9]/', '', strtolower(sanitize_text_field($token_raw))) : '';
    if (strlen($token) !== 32) {
        return;
    }
    if (!function_exists('sticky_comment_ensure_shared_links_table_exists')) {
        return;
    }
    sticky_comment_ensure_shared_links_table_exists();
    global $wpdb;
    $table_name = $wpdb->prefix . 'sticky_shared_links';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, expires_at FROM `{$table_name}` WHERE token = %s LIMIT 1",
        $token
    ), ARRAY_A);
    if (!$row) {
        return;
    }
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
        return;
    }
    $link_expires = !empty($row['expires_at']) ? strtotime($row['expires_at']) : null;
    $cookie_ttl = 31536000;
    if ($link_expires !== null) {
        $cookie_ttl = max(0, $link_expires - time());
    }
    $cookie_ttl = min($cookie_ttl, 31536000);
    $cookie_expires = time() + $cookie_ttl;
    $secure = is_ssl();
    $path = (defined('COOKIEPATH') && COOKIEPATH !== '') ? COOKIEPATH : '/';
    $domain = (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN !== '') ? COOKIE_DOMAIN : '';
    $httponly = true;
    $samesite = 'Lax';

    $cookie_opts = array(
        'expires'  => $cookie_expires,
        'path'     => $path,
        'secure'   => $secure,
        'httponly' => $httponly,
        'samesite' => $samesite,
    );
    if ($domain !== '') {
        $cookie_opts['domain'] = $domain;
    }

    setcookie('sticky_guest_token', $token, $cookie_opts);

    if (
        isset($_COOKIE['sticky_guest_id']) && is_string($_COOKIE['sticky_guest_id'])
        && preg_match('/^[a-f0-9]{32}$/', $_COOKIE['sticky_guest_id'])
    ) {
        $guest_id = $_COOKIE['sticky_guest_id'];
    } else {
        $guest_id = bin2hex(random_bytes(16));
    }
    setcookie('sticky_guest_id', $guest_id, $cookie_opts);

    sticky_comment_set_guest_id_this_request($guest_id);
}

$sticky_comment_guest_id_this_request = '';

function sticky_comment_set_guest_id_this_request($guest_id)
{
    global $sticky_comment_guest_id_this_request;
    $sticky_comment_guest_id_this_request = $guest_id;
}

function sticky_comment_get_guest_id_this_request()
{
    global $sticky_comment_guest_id_this_request;
    return is_string($sticky_comment_guest_id_this_request) ? $sticky_comment_guest_id_this_request : '';
}

add_action('init', 'sticky_comment_guest_link_maybe_set_cookies', 5);

/**
 * Keep sticky_guest token visible in URL while browsing.
 * If a valid guest token cookie exists but URL is missing sticky_guest,
 * redirect to the same URL with sticky_guest appended.
 */
function sticky_comment_guest_link_preserve_token_in_url()
{
    if (is_user_logged_in() || is_admin()) {
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        return;
    }

    if (wp_doing_ajax()) {
        return;
    }

    $has_query_token = !empty($_GET['sticky_guest']) && is_string($_GET['sticky_guest']);
    if ($has_query_token) {
        $query_token = preg_replace('/[^a-f0-9]/', '', strtolower(sanitize_text_field($_GET['sticky_guest'])));
        if (strlen($query_token) === 32) {
            return;
        }
    }

    if (empty($_COOKIE['sticky_guest_token']) || !is_string($_COOKIE['sticky_guest_token'])) {
        return;
    }

    $cookie_token = preg_replace('/[^a-f0-9]/', '', strtolower(sanitize_text_field($_COOKIE['sticky_guest_token'])));
    if (strlen($cookie_token) !== 32) {
        return;
    }

    $scheme = is_ssl() ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    if ($host === '' || $uri === '') {
        return;
    }

    $current_url = $scheme . '://' . $host . $uri;
    $target_url = add_query_arg('sticky_guest', $cookie_token, $current_url);

    if ($target_url !== $current_url) {
        wp_safe_redirect($target_url, 302);
        exit;
    }
}
add_action('template_redirect', 'sticky_comment_guest_link_preserve_token_in_url', 1);

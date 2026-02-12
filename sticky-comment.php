<?php
/*
Plugin Name: Sticky Comment
Plugin URI: https://github.com/matej/sticky-comment
Description: Add sticky notes to any webpage using a floating bubble interface. Click the bubble to add notes, view all notes, or toggle visibility.
Version: 1.0.0
Author: Matej
Author URI: https://github.com/matej
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: sticky-comment
Domain Path: /languages
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Network: false
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/php/enqueue.php';
require_once plugin_dir_path(__FILE__) . 'includes/php/guest-link.php';
require_once plugin_dir_path(__FILE__) . 'includes/php/ajax-calls.php';
require_once plugin_dir_path(__FILE__) . 'includes/php/dashboard.php';
<?php
/**
 * Plugin Name:       Club Anketa Registration for WooCommerce
 * Description:       Provides a custom WooCommerce registration "Anketa" form via shortcode and print-ready pages (Anketa + Terms). Includes SMS OTP verification for phone number validation.
 * Version:           2.2.0
 * Author:            Your Name
 * Text Domain:       club-anketa
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CLUB_ANKETA_VERSION', '2.2.0');
define('CLUB_ANKETA_PATH', plugin_dir_path(__FILE__));
define('CLUB_ANKETA_URL', plugin_dir_url(__FILE__));

require_once CLUB_ANKETA_PATH . 'includes/class-club-anketa.php';
require_once CLUB_ANKETA_PATH . 'includes/admin-settings.php';

function club_anketa_bootstrap() {
    \Club_Anketa_Registration::instance();
}
add_action('plugins_loaded', 'club_anketa_bootstrap');

register_activation_hook(__FILE__, function () {
    \Club_Anketa_Registration::instance()->register_rewrite_rules();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
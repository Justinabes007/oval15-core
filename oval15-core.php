<?php
/**
 * Plugin Name: Oval15 Core
 * Description: Core workflows for Oval15 (Pay-First checkout, tokenized Complete Registration, compatibility shims, and admin settings).
 * Version: 0.3.0
 * Author: Oval15
 */

if (!defined('ABSPATH')) exit;

define('OVAL15_CORE_VERSION', '0.3.0');

require __DIR__ . '/includes/Admin/Settings.php';
require __DIR__ . '/includes/Compat/UnhookTheme.php';
require __DIR__ . '/includes/Checkout/Flow.php';
require __DIR__ . '/includes/Registration/Endpoint.php';

add_action('plugins_loaded', function () {
    \Oval15\Core\Admin\Settings::init();

    $opt = get_option(\Oval15\Core\Admin\Settings::OPTION, []);
    $enabled = isset($opt['pay_first']) ? (bool) $opt['pay_first'] : (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'staging');

    if (!defined('OVAL15_PAY_FIRST')) {
        define('OVAL15_PAY_FIRST', apply_filters('oval15/pay_first_enabled', $enabled));
    }

    if (OVAL15_PAY_FIRST) {
        \Oval15\Core\Compat\UnhookTheme::init();
    }

    \Oval15\Core\Checkout\Flow::init();
    \Oval15\Core\Registration\Endpoint::init();
});

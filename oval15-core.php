<?php
/**
 * Plugin Name: Oval15 Core
 * Description: Core workflows for Oval15 (Pay-First checkout, tokenized Complete Registration, compatibility shims, and admin settings).
 * Version: 0.4.1
 * Author: Oval15
 */

if (!defined('ABSPATH')) exit;

define('OVAL15_CORE_VERSION', '0.4.2');

require __DIR__ . '/includes/Admin/Settings.php';
require __DIR__ . '/includes/Compat/UnhookTheme.php';
require __DIR__ . '/includes/Checkout/Flow.php';
require __DIR__ . '/includes/Registration/Endpoint.php';
require __DIR__ . '/includes/Notifications/Emails.php';
require __DIR__ . '/includes/Admin/Approvals.php'; 
require __DIR__ . '/includes/Integrations/Webhooks.php'; 
require __DIR__ . '/includes/Registration/ProfileEdit.php';
require __DIR__ . '/includes/Media/Video.php';
require __DIR__ . '/includes/Checkout/ThankyouRedirect.php';


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

    \Oval15\Core\Checkout\ThankyouRedirect::init();
    \Oval15\Core\Checkout\Flow::init();
    \Oval15\Core\Registration\Endpoint::init();
    \Oval15\Core\Notifications\Emails::init();
    \Oval15\Core\Admin\Approvals::init();
    \Oval15\Core\Integrations\Webhooks::init();
    \Oval15\Core\Registration\ProfileEdit::init();
});


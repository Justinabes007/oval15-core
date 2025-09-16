<?php
namespace Oval15\Core\Checkout;

use Oval15\Core\Admin\Settings;

if (!defined('ABSPATH')) exit;

class ThankyouRedirect {
    public static function init() {
        add_action('woocommerce_thankyou', [__CLASS__, 'redirect_to_complete'], 1, 1);
    }

    public static function redirect_to_complete($order_id) {
        if (is_admin() || !$order_id || !function_exists('wc_get_order')) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        // Only for paid-ish statuses and if Pay-First mode enabled
        $status = $order->get_status();
        $allowed = apply_filters('oval15/complete_reg_allowed_statuses', ['processing','completed']);
        if (!in_array($status, $allowed, true)) return;

        // If already completed registration once, don't redirect again
        if ((int) $order->get_meta('_oval15_registration_user') > 0) return;

        $opt = get_option(Settings::OPTION, []);
        $path = is_array($opt) ? ($opt['complete_page'] ?? '/complete-registration/') : '/complete-registration/';
        $url  = home_url( '/' . ltrim($path, '/') );

        // *** CRITICAL: Use WooCommerce's real order key ***
        $url = add_query_arg([
            'order' => $order->get_id(),
            'key'   => $order->get_order_key(),
        ], $url);

        wp_safe_redirect($url);
        exit;
    }
}
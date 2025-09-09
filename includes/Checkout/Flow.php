<?php
namespace Oval15\Core\Checkout;

use Oval15\Core\Admin\Settings;

if (!defined('ABSPATH')) exit;

class Flow {
    public static function init() {
        add_filter('woocommerce_add_to_cart_redirect', [__CLASS__, 'to_checkout']);
        add_action('woocommerce_thankyou', [__CLASS__, 'maybe_add_registration_token'], 10, 1);
    }

    public static function to_checkout($url) {
        return function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : $url;
    }

    public static function maybe_add_registration_token($order_id) {
        if (!function_exists('wc_get_order')) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        // Create token if missing
        $token = $order->get_meta('_oval15_reg_token');
        if (!$token) {
            $token = wp_generate_password(24, false, false);
            $order->update_meta_data('_oval15_reg_token', $token);
            $order->save();
        }

        $settings = get_option(Settings::OPTION, []);
        $complete_path = !empty($settings['complete_page']) ? $settings['complete_page'] : '/complete-registration/';
        $complete_url = trailingslashit(home_url($complete_path));
        $url = add_query_arg(['order' => $order_id, 'key' => $token], $complete_url);

        // Email link once
        $to = $order->get_billing_email();
        if ($to && ! $order->get_meta('_oval15_reg_email_sent')) {
            wp_mail($to, 'Complete your Oval15 registration', "Thanks for your payment. Please complete your registration:\n{$url}");
            $order->update_meta_data('_oval15_reg_email_sent', current_time('mysql'));
            $order->save();
        }

        // Thank-you notice + gentle auto-redirect
        if (function_exists('wc_print_notice')) {
            wc_print_notice(sprintf('Please <a href="%s">complete your Oval15 registration</a> to activate your profile.', esc_url($url)), 'notice');
        }

        $auto = apply_filters('oval15/thankyou_auto_redirect', true);
        $skip = isset($_GET['noredirect']) && $_GET['noredirect'] === '1';
        if ($auto && ! $skip) {
            echo '<script>(function(){setTimeout(function(){window.location.href=' . json_encode($url) . ';}, 1500);}())</script>';
        }
    }
}
<?php
namespace Oval15\Core\Compat;

use Oval15\Core\Admin\Settings;

if (!defined('ABSPATH')) exit;

class UnhookTheme {
    public static function init() {
        add_action('init', [__CLASS__, 'remove_conflicts'], 100);

        add_filter('pre_option_woocommerce_enable_guest_checkout', [__CLASS__, 'force_yes']);
        add_filter('woocommerce_checkout_registration_required', '__return_false');
        add_filter('woocommerce_enable_checkout_login_reminder', '__return_false');

        add_filter('wp_redirect', [__CLASS__, 'intercept_login_redirects'], 1, 2);
        add_filter('v_forcelogin_whitelist', [__CLASS__, 'force_login_whitelist']);
    }

    public static function remove_conflicts() {
        // Remove custom theme behaviors that force login or inject legacy forms
        remove_action('template_redirect', 'redirect_to_login_if_not_logged_in', 30);
        remove_action('template_redirect', 'wp_checkout_login_redirect', 20);

        remove_action('woocommerce_register_form_tag', 'AddEnctypeCustomRegistrationForms', 10);
        remove_action('woocommerce_register_form', 'bbloomer_add_name_woo_account_registration', 10);
        remove_filter('woocommerce_register_post', 'bbloomer_validate_name_fields', 10);
        remove_action('woocommerce_created_customer', 'bbloomer_save_name_fields', 10);

        // Defer legacy subscription thank-you so plugin can handle token redirect
        remove_action('woocommerce_thankyou', 'oval_function_on_subscription_purchase', 10);
        add_action('woocommerce_thankyou', [__CLASS__, 'guarded_subscription_purchase'], 10, 1);
    }

    public static function force_yes($value) { return 'yes'; }

    public static function intercept_login_redirects($location, $status) {
        if (function_exists('is_checkout') && is_checkout() && !is_user_logged_in()) {
            $myaccount = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
            $login_like = [$myaccount, home_url('/my-account/'), home_url('/account/'), home_url('/login/'), home_url('/shop/my-account/')];
            foreach ($login_like as $u) {
                if ($u && strpos($location, $u) === 0) {
                    return function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout/');
                }
            }
        }
        return $location;
    }

    public static function force_login_whitelist($whitelist) {
        if (!is_array($whitelist)) $whitelist = [];
        if (function_exists('wc_get_checkout_url')) $whitelist[] = wc_get_checkout_url();
        if (function_exists('wc_get_cart_url')) $whitelist[] = wc_get_cart_url();

        $opt = get_option(Settings::OPTION, []);
        $complete_path = !empty($opt['complete_page']) ? $opt['complete_page'] : '/complete-registration/';
        $whitelist[] = trailingslashit(home_url($complete_path));
        return array_unique($whitelist);
    }

    public static function guarded_subscription_purchase($order_id) {
        // Only run legacy theme callback if a customer account already exists
        if (!function_exists('oval_function_on_subscription_purchase')) return;
        if (!function_exists('wc_get_order')) return;
        $order = wc_get_order($order_id);
        if (!$order) return;
        if ((int) $order->get_user_id() > 0) {
            oval_function_on_subscription_purchase($order_id);
        }
    }
}
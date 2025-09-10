<?php
namespace Oval15\Core\Notifications;

use Oval15\Core\Admin\Settings;

if (!defined('ABSPATH')) exit;

class Emails {

    // Welcome
    const OPT_SEND_WELCOME   = 'send_welcome_on_approval';
    const OPT_WELCOME_SUBJ   = 'welcome_subject';
    const OPT_WELCOME_BODY   = 'welcome_body';
    const META_WELCOME_SENT  = '_oval15_welcome_sent';

    // Decline (NEW)
    const OPT_SEND_DECLINE   = 'send_decline_on_reject';
    const OPT_DECLINE_SUBJ   = 'decline_subject';
    const OPT_DECLINE_BODY   = 'decline_body';
    const META_DECLINE_SENT  = '_oval15_decline_sent';

    // Status meta key
    const META_APPROVED      = '_oval15_approved';

    // Common Woo/WCS email IDs you may want to disable
    private static $known_wc_email_ids = [
        'customer_processing_order'        => 'Order: Processing (Customer)',
        'customer_completed_order'         => 'Order: Completed (Customer)',
        'customer_renewal_invoice'         => 'Subscriptions: Renewal Invoice (Customer)',
        'customer_completed_renewal_order' => 'Subscriptions: Renewal Completed (Customer)',
        'customer_expired_subscription'    => 'Subscriptions: Subscription Expired (Customer)',
        'cancelled_subscription'           => 'Subscriptions: Subscription Cancelled',
    ];

    public static function init() {
        // 1) Welcome on approve (status -> yes)
        add_action('updated_user_meta', [__CLASS__, 'maybe_send_welcome_on_approval'], 10, 4);
        add_action('added_user_meta',   [__CLASS__, 'maybe_send_welcome_on_approval_added'], 10, 4);

        // 2) Decline on reject (status -> no) (NEW)
        add_action('updated_user_meta', [__CLASS__, 'maybe_send_decline_on_reject'], 10, 4);
        add_action('added_user_meta',   [__CLASS__, 'maybe_send_decline_on_reject_added'], 10, 4);

        // 3) Admin actions: send tests + previews (NEW)
        add_action('admin_post_oval15_send_test_welcome', [__CLASS__, 'handle_send_test_welcome']);
        add_action('admin_post_oval15_send_test_decline', [__CLASS__, 'handle_send_test_decline']);
        add_action('admin_post_oval15_preview_email',     [__CLASS__, 'handle_preview']);

        // 4) Optionally disable selected Woo/WCS emails
        foreach (array_keys(self::$known_wc_email_ids) as $id) {
            add_filter("woocommerce_email_enabled_{$id}", [__CLASS__, 'filter_wc_email_enabled'], 10, 2);
        }
    }

    /** Disable selected Woo/WCS emails from settings */
    public static function filter_wc_email_enabled($enabled, $email) {
        $opt = get_option(Settings::OPTION, []);
        $disabled = is_array($opt) && !empty($opt['disable_wc_emails']) ? (array) $opt['disable_wc_emails'] : [];
        if (is_object($email) && !empty($email->id) && in_array($email->id, $disabled, true)) {
            return false;
        }
        return $enabled;
    }

    /* =======================
     * Welcome email triggers
     * ======================= */

    public static function maybe_send_welcome_on_approval($meta_id, $user_id, $meta_key, $_meta_value) {
        if ($meta_key !== self::META_APPROVED) return;
        if (!is_string($_meta_value) || strtolower($_meta_value) !== 'yes') return;
        self::send_welcome_if_enabled($user_id);
    }

    public static function maybe_send_welcome_on_approval_added($meta_id, $user_id, $meta_key, $_meta_value) {
        if ($meta_key !== self::META_APPROVED) return;
        if (!is_string($_meta_value) || strtolower($_meta_value) !== 'yes') return;
        self::send_welcome_if_enabled($user_id);
    }

    private static function send_welcome_if_enabled($user_id) {
        $opt = get_option(Settings::OPTION, []);
        $enabled = is_array($opt) ? !empty($opt[self::OPT_SEND_WELCOME]) : false;
        if (!$enabled) return;

        if (get_user_meta($user_id, self::META_WELCOME_SENT, true)) return;

        $user = get_user_by('id', $user_id);
        if (!$user || !is_email($user->user_email)) return;

        $tokens = self::user_tokens($user_id, $user);
        $ok = self::send_welcome_email($user->user_email, $tokens);
        if ($ok) {
            update_user_meta($user_id, self::META_WELCOME_SENT, current_time('mysql'));
            do_action('oval15/welcome_email_sent', $user_id, $user->user_email);
        }
    }

    private static function send_welcome_email($to, array $tokens) {
        $opt = get_option(Settings::OPTION, []);
        $subject = is_array($opt) && !empty($opt[self::OPT_WELCOME_SUBJ]) ? $opt[self::OPT_WELCOME_SUBJ] : 'Welcome to Oval15';
        $body    = is_array($opt) && !empty($opt[self::OPT_WELCOME_BODY]) ? $opt[self::OPT_WELCOME_BODY] : self::default_welcome_body();
        return self::send_html($to, $subject, $body, $tokens);
    }

    private static function default_welcome_body() {
        return
        '<p>Hi {first_name},</p>
         <p>Great news — your profile has been approved and your Oval15 subscription is active.</p>
         <p>Manage your profile and subscription here:<br>
         <a href="{my_account_url}">{my_account_url}</a></p>
         <p>If you need any help, reply to this email or contact us at {support_email}.</p>
         <p>— Team Oval15</p>';
    }

    /* =======================
     * Decline email triggers (NEW)
     * ======================= */

    public static function maybe_send_decline_on_reject($meta_id, $user_id, $meta_key, $_meta_value) {
        if ($meta_key !== self::META_APPROVED) return;
        if (!is_string($_meta_value) || strtolower($_meta_value) !== 'no') return;
        self::send_decline_if_enabled($user_id);
    }

    public static function maybe_send_decline_on_reject_added($meta_id, $user_id, $meta_key, $_meta_value) {
        if ($meta_key !== self::META_APPROVED) return;
        if (!is_string($_meta_value) || strtolower($_meta_value) !== 'no') return;
        self::send_decline_if_enabled($user_id);
    }

    private static function send_decline_if_enabled($user_id) {
        $opt = get_option(Settings::OPTION, []);
        $enabled = is_array($opt) ? !empty($opt[self::OPT_SEND_DECLINE]) : false;
        if (!$enabled) return;

        $user = get_user_by('id', $user_id);
        if (!$user || !is_email($user->user_email)) return;

        $tokens = self::user_tokens($user_id, $user);
        $ok = self::send_decline_email($user->user_email, $tokens);
        if ($ok) {
            update_user_meta($user_id, self::META_DECLINE_SENT, current_time('mysql'));
            do_action('oval15/decline_email_sent', $user_id, $user->user_email);
        }
    }

    private static function send_decline_email($to, array $tokens) {
        $opt = get_option(Settings::OPTION, []);
        $subject = is_array($opt) && !empty($opt[self::OPT_DECLINE_SUBJ]) ? $opt[self::OPT_DECLINE_SUBJ] : 'Your Oval15 application';
        $body    = is_array($opt) && !empty($opt[self::OPT_DECLINE_BODY]) ? $opt[self::OPT_DECLINE_BODY] : self::default_decline_body();
        return self::send_html($to, $subject, $body, $tokens);
    }

    private static function default_decline_body() {
        return
        '<p>Hi {first_name},</p>
         <p>Thank you for your interest in Oval15. After review, we\'re not able to approve your profile at this time.</p>
         <p>You can update your profile from your account and reapply in future:<br>
         <a href="{my_account_url}">{my_account_url}</a></p>
         <p>If you have questions, please contact {support_email}.</p>
         <p>— Team Oval15</p>';
    }

    /* =======================
     * Tests + Previews (NEW)
     * ======================= */

    public static function handle_send_test_welcome() {
        if (!current_user_can('manage_woocommerce')) wp_die('Insufficient permissions.');
        check_admin_referer('oval15_send_test_welcome');

        $user = wp_get_current_user();
        $to = $user && $user->user_email ? $user->user_email : get_option('admin_email');

        $ok = self::send_welcome_email($to, self::user_tokens($user->ID ?: 0, $user));
        wp_safe_redirect(add_query_arg(['oval15_test_welcome' => $ok ? '1' : '0'], admin_url('admin.php?page=' . Settings::OPTION)));
        exit;
    }

    public static function handle_send_test_decline() {
        if (!current_user_can('manage_woocommerce')) wp_die('Insufficient permissions.');
        check_admin_referer('oval15_send_test_decline');

        $user = wp_get_current_user();
        $to = $user && $user->user_email ? $user->user_email : get_option('admin_email');

        $ok = self::send_decline_email($to, self::user_tokens($user->ID ?: 0, $user));
        wp_safe_redirect(add_query_arg(['oval15_test_decline' => $ok ? '1' : '0'], admin_url('admin.php?page=' . Settings::OPTION)));
        exit;
    }

    public static function handle_preview() {
        if (!current_user_can('manage_woocommerce')) wp_die('Insufficient permissions.');
        $type = isset($_GET['type']) ? sanitize_key($_GET['type']) : 'welcome';
        check_admin_referer('oval15_preview_email_'.$type);

        $user = wp_get_current_user();

        if ($type === 'decline') {
            $subject = self::subject_with_default(self::OPT_DECLINE_SUBJ, 'Your Oval15 application');
            $body    = self::body_with_default(self::OPT_DECLINE_BODY, self::default_decline_body());
        } else {
            $subject = self::subject_with_default(self::OPT_WELCOME_SUBJ, 'Welcome to Oval15');
            $body    = self::body_with_default(self::OPT_WELCOME_BODY, self::default_welcome_body());
        }

        $tokens = self::user_tokens($user->ID ?: 0, $user);
        $html   = self::render_preview_html(strtr($subject, $tokens), wpautop(wp_kses_post(strtr($body, $tokens))));

        // Output standalone admin page with minimal chrome
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    private static function subject_with_default($opt_key, $fallback) {
        $opt = get_option(Settings::OPTION, []);
        return is_array($opt) && !empty($opt[$opt_key]) ? $opt[$opt_key] : $fallback;
    }
    private static function body_with_default($opt_key, $fallback) {
        $opt = get_option(Settings::OPTION, []);
        return is_array($opt) && !empty($opt[$opt_key]) ? $opt[$opt_key] : $fallback;
    }

    private static function render_preview_html($subject, $body_html) {
        $styles = '
            body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f5f6f7;margin:0;padding:24px}
            .wrapper{max-width:680px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden}
            .header{background:#0b1b2b;color:#fff;padding:16px 20px;font-weight:600}
            .content{padding:20px;font-size:15px;line-height:1.6;color:#222}
            .footer{padding:12px 20px;border-top:1px solid #f0f2f4;color:#666;font-size:12px}
            a{color:#2563eb;text-decoration:none}
            h1,h2,h3{margin:0 0 10px}
        ';
        return '<!doctype html><html><head><meta charset="utf-8"><title>'.esc_html($subject).'</title><style>'.$styles.'</style></head><body>
            <div class="wrapper">
              <div class="header">'.esc_html($subject).'</div>
              <div class="content">'.$body_html.'</div>
              <div class="footer">Preview • Oval15</div>
            </div>
        </body></html>';
    }

    /* =======================
     * Public helpers
     * ======================= */

    /** Resend Welcome to a specific user (ignores already-sent marker). */
    public static function resend_to_user($user_id) {
        $user = get_user_by('id', $user_id);
        if (!$user || !is_email($user->user_email)) return false;
        $ok = self::send_welcome_email($user->user_email, self::user_tokens($user_id, $user));
        if ($ok) update_user_meta($user_id, self::META_WELCOME_SENT, current_time('mysql'));
        return $ok;
    }

    /** Optional: resend Decline email manually. */
    public static function resend_decline_to_user($user_id) {
        $user = get_user_by('id', $user_id);
        if (!$user || !is_email($user->user_email)) return false;
        $ok = self::send_decline_email($user->user_email, self::user_tokens($user_id, $user));
        if ($ok) update_user_meta($user_id, self::META_DECLINE_SENT, current_time('mysql'));
        return $ok;
    }

    private static function user_tokens($user_id, $user_obj = null) {
        $user = $user_obj ?: get_user_by('id', $user_id);
        $first = $user ? get_user_meta($user->ID, 'first_name', true) : 'Player';
        $last  = $user ? get_user_meta($user->ID, 'last_name', true) : '';
        return [
            '{first_name}'     => esc_html($first ?: ($user ? $user->user_login : 'Player')),
            '{last_name}'      => esc_html($last),
            '{my_account_url}' => esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/')),
            '{support_email}'  => esc_html(self::support_email()),
        ];
    }

    private static function support_email() {
        $opt = get_option(Settings::OPTION, []);
        return is_array($opt) && !empty($opt['support_email']) ? $opt['support_email'] : get_option('admin_email');
    }

    /** Expose list to Settings UI */
    public static function known_wc_email_ids() {
        return self::$known_wc_email_ids;
    }
}
<?php
namespace Oval15\Core\Admin;

use Oval15\Core\Notifications\Emails;

if (!defined('ABSPATH')) exit;

class Settings {
    const OPTION = 'oval15_core_settings';

    public static function init() {
        add_action('admin_init', [__CLASS__, 'register']);
        add_action('admin_menu', [__CLASS__, 'menu']);
    }

    public static function menu() {
        add_submenu_page('woocommerce', 'Oval15 Core', 'Oval15 Core', 'manage_woocommerce', self::OPTION, [__CLASS__, 'render']);
    }

    public static function render() {
        echo '<div class="wrap"><h1>Oval15 Core</h1>';

        // Notices for tests
        if (isset($_GET['oval15_test_welcome'])) {
            echo $_GET['oval15_test_welcome'] === '1'
                ? '<div class="updated notice"><p>Test welcome email sent.</p></div>'
                : '<div class="error notice"><p>Test welcome email failed to send.</p></div>';
        }
        if (isset($_GET['oval15_test_decline'])) {
            echo $_GET['oval15_test_decline'] === '1'
                ? '<div class="updated notice"><p>Test decline email sent.</p></div>'
                : '<div class="error notice"><p>Test decline email failed to send.</p></div>';
        }

        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION);
        do_settings_sections(self::OPTION);
        submit_button('Save Settings');
        echo '</form></div>';
    }

    public static function register() {
        register_setting(self::OPTION, self::OPTION);

        // ========== General ==========
        add_settings_section('main', 'General', function() {
            echo '<p>Enable Pay-First and configure the Complete Registration page.</p>';
        }, self::OPTION);

        add_settings_field('pay_first', 'Enable Pay-First mode', [__CLASS__, 'field_payfirst'], self::OPTION, 'main');
        add_settings_field('complete_page', 'Complete Registration Page', [__CLASS__, 'field_complete'], self::OPTION, 'main');
        add_settings_field('intro_html', 'Complete Registration Intro (HTML with tokens)', [__CLASS__, 'field_intro'], self::OPTION, 'main');
        add_settings_field('sla_hours', 'Approval ETA (hours)', [__CLASS__, 'field_sla'], self::OPTION, 'main');
        add_settings_field('support_email', 'Support email', [__CLASS__, 'field_support'], self::OPTION, 'main');

        // ========== Emails ==========
        add_settings_section('emails', 'Emails', function () {
            echo '<p>Control Welcome/Decline emails and disable built-in Woo/Subscriptions emails if desired.</p>';
        }, self::OPTION);

        // Welcome controls
        add_settings_field(Emails::OPT_SEND_WELCOME, 'Send “Welcome to Oval15” on approval', [__CLASS__, 'field_send_welcome'], self::OPTION, 'emails');
        add_settings_field(Emails::OPT_WELCOME_SUBJ, 'Welcome email subject', [__CLASS__, 'field_welcome_subject'], self::OPTION, 'emails');
        add_settings_field(Emails::OPT_WELCOME_BODY, 'Welcome email body (HTML, tokens allowed)', [__CLASS__, 'field_welcome_body'], self::OPTION, 'emails');
        add_settings_field('preview_welcome', 'Preview Welcome email', [__CLASS__, 'field_preview_welcome'], self::OPTION, 'emails');
        add_settings_field('send_test_welcome', 'Send test Welcome email', [__CLASS__, 'field_send_test_welcome'], self::OPTION, 'emails');

        // Decline controls (NEW)
        add_settings_field(Emails::OPT_SEND_DECLINE, 'Send Decline email on rejection', [__CLASS__, 'field_send_decline'], self::OPTION, 'emails');
        add_settings_field(Emails::OPT_DECLINE_SUBJ, 'Decline email subject', [__CLASS__, 'field_decline_subject'], self::OPTION, 'emails');
        add_settings_field(Emails::OPT_DECLINE_BODY, 'Decline email body (HTML, tokens allowed)', [__CLASS__, 'field_decline_body'], self::OPTION, 'emails');
        add_settings_field('preview_decline', 'Preview Decline email', [__CLASS__, 'field_preview_decline'], self::OPTION, 'emails');
        add_settings_field('send_test_decline', 'Send test Decline email', [__CLASS__, 'field_send_test_decline'], self::OPTION, 'emails');

        // Disable built-in emails
        add_settings_field('disable_wc_emails', 'Disable Woo/Subscriptions emails', [__CLASS__, 'field_disable_wc_emails'], self::OPTION, 'emails');
    }

    /* ---------- General fields ---------- */
    public static function field_payfirst() {
        $v = get_option(self::OPTION);
        $enabled = is_array($v) ? !empty($v['pay_first']) : false;
        echo '<label><input type="checkbox" name="'.esc_attr(self::OPTION).'[pay_first]" value="1" '.checked($enabled, true, false).'> Enable Pay-First (guest checkout allowed)</label>';
    }
    public static function field_complete() {
        $v = get_option(self::OPTION);
        $val = is_array($v) ? ($v['complete_page'] ?? '/complete-registration/') : '/complete-registration/';
        echo '<input type="text" name="'.esc_attr(self::OPTION).'[complete_page]" value="'.esc_attr($val).'" class="regular-text" placeholder="/complete-registration/">';
    }
    public static function field_intro() {
        $v = get_option(self::OPTION);
        $html = is_array($v) ? ($v['intro_html'] ?? '') : '';
        echo '<textarea name="'.esc_attr(self::OPTION).'[intro_html]" rows="6" class="large-text" placeholder="E.g. &lt;h2&gt;Thanks for your order #{order_number}&lt;/h2&gt;...">'.esc_textarea($html).'</textarea>';
        echo '<p>Tokens: <code>{order_number}</code>, <code>{billing_email}</code>, <code>{product_list}</code>, <code>{total}</code>, <code>{sla_hours}</code>, <code>{support_email}</code></p>';
    }
    public static function field_sla() {
        $v = get_option(self::OPTION);
        $sla = is_array($v) ? ($v['sla_hours'] ?? '48') : '48';
        echo '<input type="number" min="1" name="'.esc_attr(self::OPTION).'[sla_hours]" value="'.esc_attr($sla).'" class="small-text"> hours';
    }
    public static function field_support() {
        $v = get_option(self::OPTION);
        $email = is_array($v) ? ($v['support_email'] ?? get_option('admin_email')) : get_option('admin_email');
        echo '<input type="email" name="'.esc_attr(self::OPTION).'[support_email]" value="'.esc_attr($email).'" class="regular-text">';
    }

    /* ---------- Emails: Welcome ---------- */
    public static function field_send_welcome() {
        $v = get_option(self::OPTION);
        $enabled = is_array($v) ? !empty($v[Emails::OPT_SEND_WELCOME]) : true; // default ON
        echo '<label><input type="checkbox" name="'.esc_attr(self::OPTION).'['.Emails::OPT_SEND_WELCOME.']" value="1" '.checked($enabled, true, false).'> Send “Welcome to Oval15” automatically when a user is approved</label>';
    }
    public static function field_welcome_subject() {
        $v = get_option(self::OPTION);
        $val = is_array($v) ? ($v[Emails::OPT_WELCOME_SUBJ] ?? 'Welcome to Oval15') : 'Welcome to Oval15';
        echo '<input type="text" name="'.esc_attr(self::OPTION).'['.Emails::OPT_WELCOME_SUBJ.']" value="'.esc_attr($val).'" class="regular-text">';
        echo '<p class="description">Tokens: <code>{first_name}</code>, <code>{last_name}</code></p>';
    }
    public static function field_welcome_body() {
        $v = get_option(self::OPTION);
        $val = is_array($v) ? ($v[Emails::OPT_WELCOME_BODY] ?? '') : '';
        if (!$val) {
            $val = '<p>Hi {first_name},</p><p>Your profile has been approved and your Oval15 subscription is active.</p><p>Manage your account here: <a href="{my_account_url}">{my_account_url}</a></p><p>Need help? {support_email}</p>';
        }
        echo '<textarea name="'.esc_attr(self::OPTION).'['.Emails::OPT_WELCOME_BODY.']" rows="8" class="large-text">'.esc_textarea($val).'</textarea>';
        echo '<p class="description">Tokens: <code>{first_name}</code>, <code>{last_name}</code>, <code>{my_account_url}</code>, <code>{support_email}</code></p>';
    }
    public static function field_preview_welcome() {
        $url = wp_nonce_url(admin_url('admin-post.php?action=oval15_preview_email&type=welcome'), 'oval15_preview_email_welcome');
        echo '<a href="'.esc_url($url).'" target="_blank" class="button">Preview Welcome</a>';
    }
    public static function field_send_test_welcome() {
        $url = wp_nonce_url(admin_url('admin-post.php?action=oval15_send_test_welcome'), 'oval15_send_test_welcome');
        echo '<a href="'.esc_url($url).'" class="button">Send test Welcome to me</a>';
    }

    /* ---------- Emails: Decline (NEW) ---------- */
    public static function field_send_decline() {
        $v = get_option(self::OPTION);
        $enabled = is_array($v) ? !empty($v[Emails::OPT_SEND_DECLINE]) : false; // default OFF
        echo '<label><input type="checkbox" name="'.esc_attr(self::OPTION).'['.Emails::OPT_SEND_DECLINE.']" value="1" '.checked($enabled, true, false).'> Send a Decline email automatically when a user is declined</label>';
    }
    public static function field_decline_subject() {
        $v = get_option(self::OPTION);
        $val = is_array($v) ? ($v[Emails::OPT_DECLINE_SUBJ] ?? 'Your Oval15 application') : 'Your Oval15 application';
        echo '<input type="text" name="'.esc_attr(self::OPTION).'['.Emails::OPT_DECLINE_SUBJ.']" value="'.esc_attr($val).'" class="regular-text">';
        echo '<p class="description">Tokens: <code>{first_name}</code>, <code>{last_name}</code></p>';
    }
    public static function field_decline_body() {
        $v = get_option(self::OPTION);
        $val = is_array($v) ? ($v[Emails::OPT_DECLINE_BODY] ?? '') : '';
        if (!$val) {
            $val = '<p>Hi {first_name},</p><p>Thank you for your interest in Oval15. After review, we’re not able to approve your profile at this time.</p><p>You can update your profile and reapply: <a href="{my_account_url}">{my_account_url}</a></p><p>Questions? {support_email}</p>';
        }
        echo '<textarea name="'.esc_attr(self::OPTION).'['.Emails::OPT_DECLINE_BODY.']" rows="8" class="large-text">'.esc_textarea($val).'</textarea>';
        echo '<p class="description">Tokens: <code>{first_name}</code>, <code>{last_name}</code>, <code>{my_account_url}</code>, <code>{support_email}</code></p>';
    }
    public static function field_preview_decline() {
        $url = wp_nonce_url(admin_url('admin-post.php?action=oval15_preview_email&type=decline'), 'oval15_preview_email_decline');
        echo '<a href="'.esc_url($url).'" target="_blank" class="button">Preview Decline</a>';
    }
    public static function field_send_test_decline() {
        $url = wp_nonce_url(admin_url('admin-post.php?action=oval15_send_test_decline'), 'oval15_send_test_decline');
        echo '<a href="'.esc_url($url).'" class="button">Send test Decline to me</a>';
    }

    /* ---------- Emails: disable built-ins ---------- */
    public static function field_disable_wc_emails() {
        $v = get_option(self::OPTION);
        $selected = is_array($v) ? (array)($v['disable_wc_emails'] ?? []) : [];
        echo '<fieldset>';
        foreach (Emails::known_wc_email_ids() as $id => $label) {
            $name = esc_attr(self::OPTION).'[disable_wc_emails][]';
            echo '<label style="display:block;margin:2px 0"><input type="checkbox" name="'.$name.'" value="'.esc_attr($id).'" '.checked(in_array($id, $selected, true), true, false).'> '.esc_html($label).' <code>'.$id.'</code></label>';
        }
        echo '</fieldset>';
        echo '<p class="description">Select any built-in emails you want to disable to avoid duplicates or unwanted messages.</p>';
    }
}

// In register():
add_settings_section('media', 'Media (Video)', function () {
    echo '<p>Control allowed video providers and direct upload limits.</p>';
}, self::OPTION);

add_settings_field('video_hosts', 'Allowed video hosts', [__CLASS__, 'field_video_hosts'], self::OPTION, 'media');
add_settings_field('video_uploads', 'Allow direct video uploads', [__CLASS__, 'field_video_uploads'], self::OPTION, 'media');
add_settings_field('video_max_mb', 'Max upload size (MB)', [__CLASS__, 'field_video_max_mb'], self::OPTION, 'media');

// Add these methods:
public static function field_video_hosts() {
    $v = get_option(self::OPTION);
    $hosts = is_array($v) ? ($v['video_hosts'] ?? 'youtube.com, youtu.be, vimeo.com') : 'youtube.com, youtu.be, vimeo.com';
    echo '<input type="text" name="'.esc_attr(self::OPTION).'[video_hosts]" value="'.esc_attr($hosts).'" class="regular-text">';
    echo '<p class="description">Comma-separated hostnames. Only links from these hosts will be accepted (leave blank to accept any link).</p>';
}
public static function field_video_uploads() {
    $v = get_option(self::OPTION);
    $on = is_array($v) ? !empty($v['video_uploads']) : false;
    echo '<label><input type="checkbox" name="'.esc_attr(self::OPTION).'[video_uploads]" value="1" '.checked($on, true, false).'> Enable direct uploads (mp4/webm/mov)</label>';
}
public static function field_video_max_mb() {
    $v = get_option(self::OPTION);
    $mb = is_array($v) ? ($v['video_max_mb'] ?? 100) : 100;
    echo '<input type="number" name="'.esc_attr(self::OPTION).'[video_max_mb]" value="'.esc_attr((int)$mb).'" min="10" step="10" class="small-text"> MB';
}
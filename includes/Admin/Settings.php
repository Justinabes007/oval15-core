<?php
namespace Oval15\Core\Admin;

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
        echo '<div class="wrap"><h1>Oval15 Core</h1><form method="post" action="options.php">';
        settings_fields(self::OPTION);
        do_settings_sections(self::OPTION);
        submit_button('Save Settings');
        echo '</form></div>';
    }

    public static function register() {
        register_setting(self::OPTION, self::OPTION);

        add_settings_section('main', 'Oval15 Core Settings', function() {
            echo '<p>Enable Pay-First and configure the Complete Registration experience.</p>';
        }, self::OPTION);

        add_settings_field('pay_first', 'Enable Pay-First mode', [__CLASS__, 'field_payfirst'], self::OPTION, 'main');
        add_settings_field('complete_page', 'Complete Registration Page (slug or path)', [__CLASS__, 'field_complete'], self::OPTION, 'main');
        add_settings_field('intro_html', 'Complete Registration Intro (HTML with tokens)', [__CLASS__, 'field_intro'], self::OPTION, 'main');
        add_settings_field('sla_hours', 'Approval ETA (hours)', [__CLASS__, 'field_sla'], self::OPTION, 'main');
        add_settings_field('support_email', 'Support email', [__CLASS__, 'field_support'], self::OPTION, 'main');
    }

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
        echo '<textarea name="'.esc_attr(self::OPTION).'[intro_html]" rows="8" class="large-text" placeholder="E.g. &lt;h2&gt;Thanks for your order #{order_number}&lt;/h2&gt;...">'.esc_textarea($html).'</textarea>';
        echo '<p>Available tokens: <code>{order_number}</code>, <code>{billing_email}</code>, <code>{product_list}</code>, <code>{total}</code>, <code>{sla_hours}</code>, <code>{support_email}</code></p>';
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
}
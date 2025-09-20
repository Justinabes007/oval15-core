<?php
namespace Oval15\Core\Registration;

use Oval15\Core\Admin\Settings;
use Oval15\Core\Media\Video;

if (!defined('ABSPATH')) exit;

class Endpoint {
    const SHORTCODE   = 'oval15_complete_registration';
    const NONCE       = 'oval15_complete_registration_nonce';
    const ACTION_POST = 'oval15_complete_registration_submit';

    public static function init() {
        add_shortcode(self::SHORTCODE, [__CLASS__, 'shortcode']);
        add_action('template_redirect', [__CLASS__, 'maybe_handle_post']);
    }

    /** Shortcode handler */
    public static function shortcode($atts = []) {
        if (!is_user_logged_in()) {
            return '<div class="woocommerce-info">Please sign in to complete your registration.</div>';
        }

        $user_id = get_current_user_id();
        $user    = wp_get_current_user();

        // Prefill values
        $vals = self::get_prefill($user_id);

        // Order context (from thank-you page)
        $order = null;
        if (!empty($_GET['order']) && !empty($_GET['key'])) {
            $order = wc_get_order(absint($_GET['order']));
            if ($order && $order->get_order_key() !== wc_clean(wp_unslash($_GET['key']))) {
                $order = null; // invalid
            }
        }

        ob_start();
        include __DIR__ . '/../../templates/complete-registration-form.php';
        return ob_get_clean();
    }

    /** Handle form submission */
    public static function maybe_handle_post() {
        if (empty($_POST['action']) || $_POST['action'] !== self::ACTION_POST) return;
        if (!is_user_logged_in()) return;
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) {
            wc_add_notice(__('Security check failed. Please try again.'), 'error');
            return;
        }

        $user_id = get_current_user_id();
        $clean   = self::sanitize_input($_POST);

        // Update core user fields
        wp_update_user([
            'ID'         => $user_id,
            'first_name' => $clean['f_name'],
            'last_name'  => $clean['l_name'],
        ]);

        // Update meta (legacy compatible)
        foreach (self::meta_map() as $post_key => $meta_key) {
            if (isset($clean[$post_key])) {
                update_user_meta($user_id, $meta_key, $clean[$post_key]);
            }
        }

        // Profile photo
        if (!empty($_FILES['upload_photo']['name'])) {
            $photo_id = self::handle_upload('upload_photo', ['image/jpeg','image/png','image/webp']);
            if (!is_wp_error($photo_id)) {
                update_user_meta($user_id, 'upload_photo', intval($photo_id)); // legacy key
            }
        }

        // Video upload
        if (!empty($_FILES['v_upload_id']['name'])) {
            $max_mb   = 100;
            $attach_id = Video::handle_upload($_FILES['v_upload_id'], $max_mb);
            if (!is_wp_error($attach_id)) {
                update_user_meta($user_id, 'v_upload_id', (int)$attach_id);
            }
        }

        // Video link
        if (!empty($clean['yt_video'])) {
            update_user_meta($user_id, 'link', esc_url_raw($clean['yt_video'])); // legacy key
        }

        // WooCommerce order binding
        $order_id = !empty($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $order_key = !empty($_POST['order_key']) ? wc_clean(wp_unslash($_POST['order_key'])) : '';
        if ($order_id && $order_key) {
            $order = wc_get_order($order_id);
            if ($order && hash_equals($order->get_order_key(), $order_key)) {
                if (!$order->get_customer_id()) {
                    $order->set_customer_id($user_id);
                    $order->save();
                }
                update_user_meta($user_id, '_oval15_reg_completed_at', time());
                do_action('oval15/registration_completed', $user_id, $order_id);
            }
        } else {
            update_user_meta($user_id, '_oval15_reg_completed_at', time());
            do_action('oval15/registration_completed', $user_id, 0);
        }

        wc_add_notice(__('Registration details saved.'), 'success');
        wp_safe_redirect(add_query_arg(['updated' => 1], wp_get_referer() ?: home_url('/')));
        exit;
    }

    /** Prefill form values */
    private static function get_prefill($user_id) {
        $u = get_userdata($user_id);
        $getm = fn($k,$d='') => (($v=get_user_meta($user_id,$k,true))===''?$d:$v);

        return [
            'f_name'   => $u->first_name ?: '',
            'l_name'   => $u->last_name ?: '',
            'nation'   => $getm('nationality',''),
            'gender'   => $getm('gender',''),
            'dob'      => $getm('dob',''),
            'height'   => $getm('height',''),
            'weight'   => $getm('weight',''),
            'club_1'   => $getm('club',''),
            'period_1' => $getm('period',''),
            'club_2'   => $getm('club_2',''),
            'period_2' => $getm('period_2',''),
            'club_3'   => $getm('club_3',''),
            'period_3' => $getm('period_3',''),
            'p_profile'=> $getm('profile',''),
            'yt_video' => $getm('link',''),
        ];
    }

    /** Sanitize POST */
    private static function sanitize_input($src) {
        $sf = fn($k,$d='') => isset($src[$k]) ? wc_clean(wp_unslash($src[$k])) : $d;
        return [
            'f_name'   => $sf('f_name'),
            'l_name'   => $sf('l_name'),
            'nation'   => $sf('nation'),
            'gender'   => $sf('gender'),
            'dob'      => $sf('dob'),
            'height'   => preg_replace('/[^0-9.]/','',$sf('height')),
            'weight'   => preg_replace('/[^0-9.]/','',$sf('weight')),
            'club_1'   => $sf('club_1'),
            'period_1' => $sf('period_1'),
            'club_2'   => $sf('club_2'),
            'period_2' => $sf('period_2'),
            'club_3'   => $sf('club_3'),
            'period_3' => $sf('period_3'),
            'p_profile'=> isset($src['p_profile']) ? wp_kses_post(wp_unslash($src['p_profile'])) : '',
            'yt_video' => isset($src['yt_video']) ? esc_url_raw(wp_unslash($src['yt_video'])) : '',
        ];
    }

    /** Map POST keys → user_meta keys */
    private static function meta_map() {
        return [
            'nation'   => 'nationality',
            'gender'   => 'gender',
            'dob'      => 'dob',
            'height'   => 'height',
            'weight'   => 'weight',
            'club_1'   => 'club',
            'period_1' => 'period',
            'club_2'   => 'club_2',
            'period_2' => 'period_2',
            'club_3'   => 'club_3',
            'period_3' => 'period_3',
            'p_profile'=> 'profile',
            'yt_video' => 'link',
        ];
    }

    /** Upload helper for images */
    private static function handle_upload($field, $mimes = []) {
        if (empty($_FILES[$field]['name'])) return new \WP_Error('no_file', 'No file uploaded');

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $overrides = ['test_form' => false, 'mimes' => $mimes];
        $file = wp_handle_upload($_FILES[$field], $overrides);

        if (isset($file['error'])) {
            return new \WP_Error('upload_error', $file['error']);
        }

        $attachment = [
            'post_mime_type' => $file['type'],
            'post_title'     => sanitize_file_name(basename($file['file'])),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];
        $attach_id = wp_insert_attachment($attachment, $file['file']);
        wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $file['file']));
        return $attach_id;
    }
}
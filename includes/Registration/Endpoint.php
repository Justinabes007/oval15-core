<?php
namespace Oval15\Core\Registration;

use Oval15\Core\Admin\Settings;
use Oval15\Core\Media\Video;

if (!defined('ABSPATH')) exit;

class Endpoint {
    const SLUG         = 'oval15-complete-registration';
    const SHORTCODE    = 'oval15_complete_registration';
    const NONCE        = 'oval15_complete_registration_nonce';
    const ACTION_POST  = 'oval15_complete_registration_submit';

    public static function init() {
        add_shortcode(self::SHORTCODE, [__CLASS__, 'shortcode']);
        add_action('template_redirect', [__CLASS__, 'maybe_handle_post']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue_form_assets']);
    }

    /** Enqueue overlay CSS only where shortcode is used */
    public static function maybe_enqueue_form_assets() {
        if (!is_singular()) return;
        global $post;
        if (!$post) return;
        if (has_shortcode($post->post_content, self::SHORTCODE)) {
            $css = '#oval15-loading{display:none;position:fixed;inset:0;background:rgba(255,255,255,.7);z-index:9999}
                    #oval15-loading .spinner{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:16px}';
            wp_add_inline_style('select2', $css);
        }
    }

    /** Render Complete Registration form */
    public static function shortcode($atts = []) {
        if (!is_user_logged_in()) {
            return '<div class="woocommerce-info">Please sign in to complete your registration.</div>';
        }

        $u = wp_get_current_user();
        $data = self::get_prefill($u->ID);

        ob_start();
        include __DIR__ . '/../../templates/complete-registration-form.php';
        return ob_get_clean();
    }

    /** Handle submission */
    public static function maybe_handle_post() {
        if (empty($_POST['action']) || $_POST['action'] !== self::ACTION_POST) return;
        if (!is_user_logged_in()) return;
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) {
            wc_add_notice(__('Security check failed. Please try again.'), 'error');
            return;
        }

        $user_id = get_current_user_id();
        $clean   = self::sanitize_input($_POST);

        // Save core fields
        wp_update_user([
            'ID'         => $user_id,
            'first_name' => $clean['f_name'],
            'last_name'  => $clean['l_name'],
        ]);

        // Save meta
        foreach (self::meta_map() as $post_key => $meta_key) {
            if (array_key_exists($post_key, $clean)) {
                update_user_meta($user_id, $meta_key, $clean[$post_key]);
            }
        }

        // Save arrays
        update_user_meta($user_id, 'secondary-position', $clean['secondary-position']);
        update_user_meta($user_id, 'passport', $clean['passport']);

        // Combined phone
        if (!empty($clean['country_code']) && !empty($clean['c_number'])) {
            $combined = trim($clean['country_code']) . ' ' . trim($clean['c_number']);
            update_user_meta($user_id, 'contact_number_combined', $combined);
        }

        // Handle photo
        if (!empty($_FILES['upload_photo']['name'])) {
            $photo_id = self::handle_upload('upload_photo', ['image/jpeg','image/png','image/webp']);
            if (!is_wp_error($photo_id)) {
                update_user_meta($user_id, 'profile_photo_id', intval($photo_id));
            }
        }

        // Handle video upload
        if (!empty($_FILES['v_upload_id']['name'])) {
            $video_attachment_id = Video::handle_upload($_FILES['v_upload_id'], 100);
            if (!is_wp_error($video_attachment_id) && $video_attachment_id) {
                update_user_meta($user_id, 'v_upload_id', intval($video_attachment_id));
            } else if (is_wp_error($video_attachment_id)) {
                wc_add_notice($video_attachment_id->get_error_message(), 'error');
            }
        }

        // Handle video URL
        if (!empty($clean['yt_video'])) {
            update_user_meta($user_id, 'yt_video', esc_url_raw($clean['yt_video']));
        }

        // Woo order binding
        $order_id  = !empty($_POST['order_id']) ? absint($_POST['order_id']) : 0;
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

    /** Prefill user values */
    private static function get_prefill($user_id) {
        $u = get_userdata($user_id);
        $getm = function($k,$d='') use($user_id){ $v=get_user_meta($user_id,$k,true); return $v===''?$d:$v; };

        return [
            'f_name'  => $u->first_name ?: '',
            'l_name'  => $u->last_name ?: '',
            'gender'  => $getm('gender','Male'),
            'dob'     => $getm('dob',''),
            'main-position' => $getm('main-position',''),
            'secondary-position' => (array) get_user_meta($user_id,'secondary-position',true) ?: [],
            'lop'     => $getm('lop','Amateur'),
            'c_number'=> $getm('c_number',''),
            'country_code' => $getm('country_code',''),
            'nation'  => $getm('nation',''),
            'passport'=> (array) get_user_meta($user_id,'passport',true) ?: [],
            'current_location_country' => $getm('current_location_country',''),
            'weight'  => $getm('weight',''),
            'height'  => $getm('height',''),
            'available_from_year'  => $getm('available_from_year',''),
            'available_from_month' => $getm('available_from_month',''),
            'club_1'  => $getm('club_1',''),
            'tournament_1' => $getm('tournament_1',''),
            'period_1'=> $getm('period_1',''),
            'club_2'  => $getm('club_2',''),
            'tournament_2' => $getm('tournament_2',''),
            'period_2'=> $getm('period_2',''),
            'club_3'  => $getm('club_3',''),
            'tournament_3' => $getm('tournament_3',''),
            'period_3'=> $getm('period_3',''),
            'p_profile'=> $getm('p_profile',''),
            'yt_video' => $getm('yt_video',''),
        ];
    }

    /** Sanitize POST */
    private static function sanitize_input($src) {
        $clean = [];
        $sf = function($k,$d='') use($src){ return isset($src[$k]) ? wc_clean(wp_unslash($src[$k])) : $d; };

        $clean['f_name'] = $sf('f_name');
        $clean['l_name'] = $sf('l_name');
        $clean['gender'] = $sf('gender');
        $clean['dob']    = $sf('dob');
        $clean['main-position'] = $sf('main-position');
        $clean['secondary-position'] = isset($src['secondary-position']) ? array_map('wc_clean', (array) $src['secondary-position']) : [];
        $clean['lop'] = $sf('lop');
        $clean['c_number'] = $sf('c_number');
        $clean['country_code'] = $sf('country_code');
        $clean['nation'] = $sf('nation');
        $clean['passport'] = isset($src['passport']) ? array_map('wc_clean', (array) $src['passport']) : [];
        $clean['current_location_country'] = $sf('current_location_country');
        $clean['weight'] = preg_replace('/[^0-9.]/','',$sf('weight'));
        $clean['height'] = preg_replace('/[^0-9.]/','',$sf('height'));
        $clean['available_from_year']  = preg_replace('/[^0-9]/','',$sf('available_from_year'));
        $clean['available_from_month'] = preg_replace('/[^0-9]/','',$sf('available_from_month'));

        for ($i=1;$i<=3;$i++) {
            $clean["club_{$i}"] = $sf("club_{$i}");
            $clean["tournament_{$i}"] = $sf("tournament_{$i}");
            $clean["period_{$i}"] = $sf("period_{$i}");
        }

        $clean['p_profile'] = isset($src['p_profile']) ? wp_kses_post(wp_unslash($src['p_profile'])) : '';
        $clean['yt_video']  = isset($src['yt_video']) ? esc_url_raw(wp_unslash($src['yt_video'])) : '';

        return $clean;
    }

    /** Map POST -> user_meta */
    private static function meta_map() {
        return [
            'gender'  => 'gender',
            'dob'     => 'dob',
            'main-position' => 'main-position',
            'lop'     => 'lop',
            'c_number'=> 'c_number',
            'country_code' => 'country_code',
            'nation'  => 'nation',
            'current_location_country' => 'current_location_country',
            'weight'  => 'weight',
            'height'  => 'height',
            'available_from_year'  => 'available_from_year',
            'available_from_month' => 'available_from_month',
            'club_1'  => 'club_1',
            'tournament_1' => 'tournament_1',
            'period_1'=> 'period_1',
            'club_2'  => 'club_2',
            'tournament_2' => 'tournament_2',
            'period_2'=> 'period_2',
            'club_3'  => 'club_3',
            'tournament_3' => 'tournament_3',
            'period_3'=> 'period_3',
            'p_profile'=> 'p_profile',
            'yt_video' => 'yt_video',
        ];
    }

    /** Fallback upload helper */
    private static function handle_upload($field, $mimes = []) {
        if (empty($_FILES[$field]['name'])) return new \WP_Error('no_file', 'No file uploaded');

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $overrides = ['test_form' => false, 'mimes' => $mimes];
        $file = wp_handle_upload($_FILES[$field], $overrides);
        if (isset($file['error'])) return new \WP_Error('upload_error', $file['error']);

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

    /** Positions select */
    private static function options_positions($selected = [], $with_placeholder = false) {
        $positions = [
            'Prop (1)','Hooker','Prop (3)','Lock (4)','Lock (5)','Flank (Openside)','Flank (Blindside)',
            'No 8','Scrumhalf','Flyhalf','Inside Centre (12)','Outside Centre (13)','Wing','Fullback','Utility Back'
        ];
        $selected = (array)$selected;
        $out = '';
        if ($with_placeholder) {
            $out .= '<option value="">' . esc_html('Select Main Position') . '</option>';
        }
        foreach ($positions as $p) {
            $sel = in_array($p, $selected, true) ? ' selected' : '';
            $out .= '<option value="'.esc_attr($p).'"'.$sel.'>'.esc_html($p).'</option>';
        }
        return $out;
    }

    /** Country list */
    private static function countries() {
        return [
            'Angola','Argentina','Australia','Belgium','Brazil','Canada','Chile','China','Czech Republic',
            'Democratic Republic of Congo','England','European Union (EU)','Fiji','France','Georgia','Germany',
            'Ghana','Hong Kong','Hungary','Ireland','Italy','Japan','Kenya','Madagascar','Namibia','Netherlands',
            'New Zealand','Nigeria','Poland','Portugal','Qatar','Romania','Russia','Samoa','Scotland','South Africa',
            'Spain','Sweden','Tanzania','Thailand','Tonga','Trinidad and Tobago','Tunisia','UAE','Uganda','Ukraine',
            'United Kingdom','United States','Uruguay','Wales','Zambia','Zimbabwe'
        ];
    }
}
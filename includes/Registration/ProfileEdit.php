<?php
namespace Oval15\Core\Registration;

use Oval15\Core\Admin\Settings;
use Oval15\Core\Media\Video;

if (!defined('ABSPATH')) exit;

class ProfileEdit {

    public static function init() {
        add_shortcode('oval15_profile_edit', [__CLASS__, 'render_shortcode']);
    }

    public static function render_shortcode() {
        if (!is_user_logged_in()) {
            $login = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url();
            return '<p>You need to <a href="'.esc_url($login).'">log in</a> to edit your profile.</p>';
        }

        $user_id = get_current_user_id();
        $notice  = '';

        if (!empty($_POST['oval15_do_profile_save'])) {
            if (!isset($_POST['_oval15_nonce']) || !wp_verify_nonce($_POST['_oval15_nonce'], 'oval15_profile_edit')) {
                $notice = '<div class="woocommerce-error">Security check failed. Please try again.</div>';
            } else {
                $resp = self::handle_submit($user_id);
                if (is_wp_error($resp)) {
                    $notice = '<div class="woocommerce-error">'.esc_html($resp->get_error_message()).'</div>';
                } else {
                    $notice = '<div class="woocommerce-message">Profile updated.</div>';
                }
            }
        }

        return $notice . self::form_html($user_id);
    }

    private static function form_html($user_id) {
        $g = function($k, $default='') use ($user_id) { $v = get_user_meta($user_id, $k, true); return $v !== '' ? $v : $default; };
        $user = get_user_by('id', $user_id);
        $approved = strtolower((string) get_user_meta($user_id, '_oval15_approved', true)) === 'yes';

        // Prefill video links
        $links_csv = (string) get_user_meta($user_id, 'v_links', true);
        $links = array_values(array_filter(array_map('trim', explode(',', $links_csv))));
        $l1 = $links[0] ?? '';
        $l2 = $links[1] ?? '';
        $l3 = $links[2] ?? '';

        $opt = get_option(Settings::OPTION, []);
        $allow_upload = is_array($opt) ? !empty($opt['video_uploads']) : false;

        ob_start(); ?>
        <div class="oval15-profile-edit">
            <div class="oval15-status" style="background:#f7f7f9;padding:12px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:16px">
                <strong>Status:</strong>
                <?php echo $approved ? '<span style="color:#25855a">Approved</span>' : '<span style="color:#b7791f">Pending/Not approved</span>'; ?>
            </div>

            <form method="post" enctype="multipart/form-data" class="woocommerce-EditAccountForm edit-account">
                <?php wp_nonce_field('oval15_profile_edit', '_oval15_nonce'); ?>

                <!-- form fields remain unchanged -->
                <?php /* … all your existing HTML unchanged … */ ?>

                <p class="form-row"><button type="submit" class="button alt" name="oval15_do_profile_save" value="1">Save Profile</button></p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function handle_submit($user_id) {
        $required = ['first_name','last_name'];
        foreach ($required as $r) {
            $val = isset($_POST[$r]) ? trim((string) $_POST[$r]) : '';
            if ($val === '') return new \WP_Error('oval15_missing', 'Please complete all required fields (first & last name).');
        }

        // 50-word cap
        if (!empty($_POST['p_profile'])) {
            $words = preg_split('/\s+/', trim(wp_strip_all_tags((string) $_POST['p_profile'])));
            if (count($words) > 50) {
                return new \WP_Error('oval15_profile_words', 'Please keep this section to 50 words or fewer.');
            }
        }

        $changes = [];

        // Names
        $first = sanitize_text_field($_POST['first_name'] ?? '');
        $last  = sanitize_text_field($_POST['last_name'] ?? '');
        if ($first !== get_user_meta($user_id, 'first_name', true)) { update_user_meta($user_id, 'first_name', $first); $changes['first_name'] = $first; }
        if ($last  !== get_user_meta($user_id, 'last_name', true))  { update_user_meta($user_id, 'last_name',  $last);  $changes['last_name']  = $last;  }

        // Scalars (extended fields)
        $map = ['contact_number','dob','p_profile','nation','current_location_country','gender','height','weight','years','months','level','league','period_1','club_1','period_2','club_2','period_3','club_3','reason','tournament_1','tournament_2','tournament_3','main-position'];
        foreach ($map as $k) {
            if (isset($_POST[$k])) {
                $new = sanitize_text_field((string) $_POST[$k]);
                $old = (string) get_user_meta($user_id, $k, true);
                if ($new !== $old) { update_user_meta($user_id, $k, $new); $changes[$k] = $new; }
            }
        }

        // ✅ Dual-save for legacy keys
        if (!empty($_POST['p_profile'])) {
            update_user_meta($user_id, 'profile', sanitize_textarea_field($_POST['p_profile'])); // legacy
        }
        if (!empty($_POST['nation'])) {
            update_user_meta($user_id, 'nationality', sanitize_text_field($_POST['nation'])); // legacy
        }
        if (!empty($_POST['period_1'])) update_user_meta($user_id, 'period', sanitize_text_field($_POST['period_1']));
        if (!empty($_POST['club_1']))   update_user_meta($user_id, 'club', sanitize_text_field($_POST['club_1']));
        if (!empty($_POST['period_2'])) update_user_meta($user_id, 'period_2', sanitize_text_field($_POST['period_2']));
        if (!empty($_POST['club_2']))   update_user_meta($user_id, 'club_2', sanitize_text_field($_POST['club_2']));
        if (!empty($_POST['period_3'])) update_user_meta($user_id, 'period_3', sanitize_text_field($_POST['period_3']));
        if (!empty($_POST['club_3']))   update_user_meta($user_id, 'club_3', sanitize_text_field($_POST['club_3']));

        // Arrays → CSV (positions, countries, passports)
        if (isset($_POST['secondary-position'])) {
            $arr = array_map('sanitize_text_field', (array) $_POST['secondary-position']);
            $new = implode(',', $arr); $old = (string) get_user_meta($user_id, 'secondary-position', true);
            if ($new !== $old) { update_user_meta($user_id, 'secondary-position', $new); $changes['secondary-position'] = $new; }
        }
        if (isset($_POST['interested_country'])) {
            $arr = array_filter(array_map('sanitize_text_field', (array) $_POST['interested_country']));
            $new = implode(',', array_slice($arr, 0, 3)); $old = (string) get_user_meta($user_id, 'interested_country', true);
            if ($new !== $old) { update_user_meta($user_id, 'interested_country', $new); $changes['interested_country'] = $new; }
        }
        if (isset($_POST['passport'])) {
            $arr = array_filter(array_map('sanitize_text_field', (array) $_POST['passport']));
            $new = implode(',', $arr); $old = (string) get_user_meta($user_id, 'passport', true);
            if ($new !== $old) { update_user_meta($user_id, 'passport', $new); $changes['passport'] = $new; }
        }

        // Flags
        $sole  = !empty($_POST['oval15_sole_rep']) ? 'yes' : '';
        $mcons = !empty($_POST['marketing_consent']) ? 'yes' : '';
        update_user_meta($user_id, '_oval15_sole_rep', $sole);
        update_user_meta($user_id, '_oval15_marketing_consent', $mcons);

        // Media: photo
        if (!empty($_FILES['p_photo']['name'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $overrides = ['test_form' => false, 'mimes' => ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp']];
            $file = wp_handle_upload($_FILES['p_photo'], $overrides);
            if (isset($file['error'])) {
                return new \WP_Error('oval15_photo', 'Photo upload failed: '.$file['error']);
            }
            $attachment = [
                'post_mime_type' => $file['type'],
                'post_title'     => sanitize_file_name(basename($file['file'])),
                'post_content'   => '',
                'post_status'    => 'inherit'
            ];
            $attach_id = wp_insert_attachment($attachment, $file['file']);
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $metadata = wp_generate_attachment_metadata($attach_id, $file['file']);
            wp_update_attachment_metadata($attach_id, $metadata);

            update_user_meta($user_id, 'p_photo_id', $attach_id);
            update_user_meta($user_id, 'upload_photo', $attach_id); // ✅ legacy mirror
            $changes['upload_photo'] = (int) $attach_id;
        }

        // Video links
        $opt = get_option(Settings::OPTION, []);
        $hosts = [];
        if (is_array($opt) && !empty($opt['video_hosts'])) {
            $hosts = array_filter(array_map('trim', explode(',', $opt['video_hosts'])));
        }
        $links_in = array_filter(array_map('trim', (array)($_POST['v_links'] ?? [])));
        $links_ok = [];
        foreach ($links_in as $u) {
            if (!filter_var($u, FILTER_VALIDATE_URL)) continue;
            if ($hosts && !Video::host_allowed($u, $hosts)) continue;
            $links_ok[] = esc_url_raw($u);
        }
        $new_csv = implode(',', $links_ok);
        $old_csv = (string) get_user_meta($user_id, 'v_links', true);
        if ($new_csv !== $old_csv) {
            if ($new_csv) {
                update_user_meta($user_id, 'v_links', $new_csv);
                update_user_meta($user_id, 'v_link', $links_ok[0] ?? ''); // old field
                update_user_meta($user_id, 'link', $links_ok[0] ?? '');   // ✅ legacy primary
            } else {
                delete_user_meta($user_id, 'v_links');
                delete_user_meta($user_id, 'link');
            }
            $changes['v_links'] = $new_csv;
        }

        // Optional direct video upload
        $allow_upload = is_array($opt) ? !empty($opt['video_uploads']) : false;
        if ($allow_upload && !empty($_FILES['v_upload']['name'])) {
            $max_mb = is_array($opt) ? (int)($opt['video_max_mb'] ?? 100) : 100;
            $attach_id = Video::handle_upload($_FILES['v_upload'], $max_mb);
            if (is_wp_error($attach_id)) {
                return $attach_id;
            }
            update_user_meta($user_id, 'v_upload_id', (int) $attach_id);
            $changes['v_upload_id'] = (int) $attach_id;
        }

        if (!empty($changes)) {
            do_action('oval15/profile_updated', $user_id, $changes);
        }

        return true;
    }
}

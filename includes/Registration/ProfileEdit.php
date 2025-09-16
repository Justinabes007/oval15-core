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

                <h3>Personal Details</h3>
                <p class="form-row form-row-first">
                    <label>First name&nbsp;<span class="required">*</span></label>
                    <input type="text" class="input-text" name="first_name" value="<?php echo esc_attr(get_user_meta($user_id, 'first_name', true)); ?>" required>
                </p>
                <p class="form-row form-row-last">
                    <label>Last name&nbsp;<span class="required">*</span></label>
                    <input type="text" class="input-text" name="last_name" value="<?php echo esc_attr(get_user_meta($user_id, 'last_name', true)); ?>" required>
                </p>
                <p class="form-row form-row-first">
                    <label>Contact number</label>
                    <input type="tel" class="input-text" name="contact_number" value="<?php echo esc_attr($g('contact_number')); ?>">
                </p>
                <p class="form-row form-row-last">
                    <label>Date of Birth</label>
                    <input type="date" class="input-text" name="dob" value="<?php echo esc_attr($g('dob')); ?>">
                </p>
                <p class="form-row form-row-first">
                    <label>Gender</label>
                    <?php $gender = $g('gender'); ?>
                    <select name="gender">
                        <option value="">Select…</option>
                        <option <?php selected($gender, 'Male'); ?>>Male</option>
                        <option <?php selected($gender, 'Female'); ?>>Female</option>
                        <option <?php selected($gender, 'Other'); ?>>Other</option>
                    </select>
                </p>
                <p class="form-row form-row-last">
                    <label>Nationality</label>
                    <input type="text" class="input-text" name="nation" value="<?php echo esc_attr($g('nation')); ?>">
                </p>
                <p class="form-row form-row-first">
                    <label>Current Country</label>
                    <input type="text" class="input-text" name="current_location_country" value="<?php echo esc_attr($g('current_location_country')); ?>">
                </p>
                <p class="form-row form-row-last">
                    <label>Height (cm)</label>
                    <input type="number" class="input-text" name="height" value="<?php echo esc_attr($g('height')); ?>" step="1" min="0">
                </p>
                <p class="form-row form-row-first">
                    <label>Weight (kg)</label>
                    <input type="number" class="input-text" name="weight" value="<?php echo esc_attr($g('weight')); ?>" step="0.1" min="0">
                </p>
                <p class="form-row form-row-last">
                    <label>Years playing</label>
                    <input type="number" class="input-text" name="years" value="<?php echo esc_attr($g('years')); ?>" step="1" min="0">
                </p>
                <p class="form-row form-row-first">
                    <label>Months into current year</label>
                    <input type="number" class="input-text" name="months" value="<?php echo esc_attr($g('months')); ?>" step="1" min="0" max="12">
                </p>

                <h3>Positions</h3>
                <?php $main = $g('main-position'); $sec = array_filter(array_map('trim', explode(',', $g('secondary-position')))); ?>
                <p class="form-row">
                    <label>Main Position</label>
                    <select name="main-position">
                        <?php
                        $opts = ['','Prop (1)','Hooker','Prop (3)','Lock (4)','Lock (5)','Flank (Openside)','Flank (Blindside)','No 8','Scrumhalf','Flyhalf','Inside Centre (12)','Outside Centre (13)','Wing','Fullback','Utility Back'];
                        foreach ($opts as $o) {
                            echo '<option value="'.esc_attr($o).'" '.selected($main, $o, false).'>'.esc_html($o ?: 'Select…').'</option>';
                        }
                        ?>
                    </select>
                </p>
                <p class="form-row">
                    <label>Secondary Positions (Ctrl/Cmd to select multiple)</label>
                    <select name="secondary-position[]" multiple size="6">
                        <?php foreach ($opts as $o) { if ($o==='') continue;
                            echo '<option value="'.esc_attr($o).'" '.selected(in_array($o, $sec, true), true, false).'>'.esc_html($o).'</option>';
                        } ?>
                    </select>
                </p>

                <h3>Experience</h3>
                <p class="form-row form-row-first"><label>Highest Level Played</label><input type="text" class="input-text" name="level" value="<?php echo esc_attr($g('level')); ?>"></p>
                <p class="form-row form-row-last"><label>League</label><input type="text" class="input-text" name="league" value="<?php echo esc_attr($g('league')); ?>"></p>
                <p class="form-row form-row-first"><label>Period 1</label><input type="text" class="input-text" name="period_1" value="<?php echo esc_attr($g('period_1')); ?>"></p>
                <p class="form-row form-row-last"><label>Club/Team 1</label><input type="text" class="input-text" name="club_1" value="<?php echo esc_attr($g('club_1')); ?>"></p>
                <p class="form-row form-row-first"><label>Period 2</label><input type="text" class="input-text" name="period_2" value="<?php echo esc_attr($g('period_2')); ?>"></p>
                <p class="form-row form-row-last"><label>Club/Team 2</label><input type="text" class="input-text" name="club_2" value="<?php echo esc_attr($g('club_2')); ?>"></p>
                <p class="form-row form-row-first"><label>Period 3</label><input type="text" class="input-text" name="period_3" value="<?php echo esc_attr($g('period_3')); ?>"></p>
                <p class="form-row form-row-last"><label>Club/Team 3</label><input type="text" class="input-text" name="club_3" value="<?php echo esc_attr($g('club_3')); ?>"></p>
                <p class="form-row"><label>Reason for leaving last club</label><input type="text" class="input-text" name="reason" value="<?php echo esc_attr($g('reason')); ?>"></p>

                <h3>Tournaments</h3>
                <p class="form-row form-row-first"><label>Tournament 1</label><input type="text" class="input-text" name="tournament_1" value="<?php echo esc_attr($g('tournament_1')); ?>"></p>
                <p class="form-row form-row-last"><label>Tournament 2</label><input type="text" class="input-text" name="tournament_2" value="<?php echo esc_attr($g('tournament_2')); ?>"></p>
                <p class="form-row form-row-first"><label>Tournament 3</label><input type="text" class="input-text" name="tournament_3" value="<?php echo esc_attr($g('tournament_3')); ?>"></p>

                <h3>International Interest & Passports</h3>
                <?php
                    $ic = array_filter(array_map('trim', explode(',', $g('interested_country'))));
                    $ps = array_filter(array_map('trim', explode(',', $g('passport'))));
                ?>
                <p class="form-row"><label>Interested Countries (up to 3)</label>
                    <input type="text" class="input-text" name="interested_country[]" value="<?php echo esc_attr($ic[0] ?? ''); ?>" placeholder="Country 1">
                    <input type="text" class="input-text" name="interested_country[]" value="<?php echo esc_attr($ic[1] ?? ''); ?>" placeholder="Country 2">
                    <input type="text" class="input-text" name="interested_country[]" value="<?php echo esc_attr($ic[2] ?? ''); ?>" placeholder="Country 3">
                </p>
                <p class="form-row"><label>Passport(s)</label>
                    <input type="text" class="input-text" name="passport[]" value="<?php echo esc_attr($ps[0] ?? ''); ?>" placeholder="e.g. South African">
                    <input type="text" class="input-text" name="passport[]" value="<?php echo esc_attr($ps[1] ?? ''); ?>" placeholder="e.g. British">
                </p>

                <h3>Media</h3>
                <?php
                $hosts_hint = '';
                if (is_array($opt) && !empty($opt['video_hosts'])) {
                    $hosts_hint = ' Allowed hosts: '.esc_html($opt['video_hosts']).'.';
                }
                ?>
                <p class="form-row"><label>Video links (YouTube/Vimeo; up to 3)</label>
                    <input type="url" class="input-text" name="v_links[]" value="<?php echo esc_attr($l1); ?>" placeholder="https://">
                    <input type="url" class="input-text" name="v_links[]" value="<?php echo esc_attr($l2); ?>" placeholder="https://">
                    <input type="url" class="input-text" name="v_links[]" value="<?php echo esc_attr($l3); ?>" placeholder="https://">
                    <small><?php echo $hosts_hint; ?></small>
                </p>
                <?php
                $photo_id = (int) get_user_meta($user_id, 'p_photo_id', true);
                $photo_url = $photo_id ? wp_get_attachment_image_url($photo_id, 'thumbnail') : '';
                ?>
                <p class="form-row"><label>Profile photo</label>
                    <?php if ($photo_url) echo '<br><img src="'.esc_url($photo_url).'" alt="" style="max-width:120px;border-radius:8px;border:1px solid #e5e7eb;margin:6px 0">'; ?>
                    <input type="file" name="p_photo" accept="image/*">
                </p>
                <?php if ($allow_upload) : ?>
                <?php $up_id = (int) get_user_meta($user_id, 'v_upload_id', true); ?>
                <p class="form-row"><label>Upload highlight clip (mp4/webm/mov)</label>
                    <?php if ($up_id) {
                        echo '<div style="margin:8px 0">'.Video::render_uploaded($up_id).'</div>';
                    } ?>
                    <input type="file" name="v_upload" accept="video/mp4,video/webm,video/quicktime">
                </p>
                <?php endif; ?>

                <h3>Strengths & Achievements</h3>
                <p class="form-row">
                    <label>Strengths of game &amp; Rugby Achievements (max 50 words)</label>
                    <textarea name="p_profile" rows="4" maxlength="800" placeholder="Max 50 words"><?php echo esc_textarea($g('p_profile')); ?></textarea>
                </p>

                <p class="form-row">
                    <label><input type="checkbox" name="oval15_sole_rep" value="yes" <?php checked(get_user_meta($user_id, '_oval15_sole_rep', true), 'yes'); ?>> I confirm Oval15 is my sole representative</label>
                </p>
                <p class="form-row">
                    <label><input type="checkbox" name="marketing_consent" value="yes" <?php checked(get_user_meta($user_id, '_oval15_marketing_consent', true), 'yes'); ?>> I agree to receive occasional updates</label>
                </p>

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

        // Scalars
        $map = ['contact_number','dob','p_profile','nation','current_location_country','gender','height','weight','years','months','level','league','period_1','club_1','period_2','club_2','period_3','club_3','reason','tournament_1','tournament_2','tournament_3','main-position'];
        foreach ($map as $k) {
            if (isset($_POST[$k])) {
                $new = sanitize_text_field((string) $_POST[$k]);
                $old = (string) get_user_meta($user_id, $k, true);
                if ($new !== $old) { update_user_meta($user_id, $k, $new); $changes[$k] = $new; }
            }
        }

        // Arrays → CSV
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
        if ($sole  !== get_user_meta($user_id, '_oval15_sole_rep', true))        { update_user_meta($user_id, '_oval15_sole_rep', $sole); $changes['_oval15_sole_rep'] = $sole; }
        if ($mcons !== get_user_meta($user_id, '_oval15_marketing_consent', true)){ update_user_meta($user_id, '_oval15_marketing_consent', $mcons); $changes['_oval15_marketing_consent'] = $mcons; }

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
            $changes['p_photo_id'] = (int) $attach_id;
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
                update_user_meta($user_id, 'v_link', $links_ok[0] ?? ''); // legacy primary
            } else {
                delete_user_meta($user_id, 'v_links');
            }
            $changes['v_links'] = $new_csv;
        }

        // Optional direct video upload
        $allow_upload = is_array($opt) ? !empty($opt['video_uploads']) : false;
        if ($allow_upload && !empty($_FILES['v_upload']['name'])) {
            $max_mb = is_array($opt) ? (int)($opt['video_max_mb'] ?? 100) : 100;
            $attach_id = Video::handle_upload($_FILES['v_upload'], $max_mb);
            if (is_wp_error($attach_id)) {
                return $attach_id; // surfaces error back to the form
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
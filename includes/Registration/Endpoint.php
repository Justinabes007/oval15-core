<?php
namespace Oval15\Core\Registration;

use Oval15\Core\Admin\Settings;
use Oval15\Core\Media\Video;

if (!defined('ABSPATH')) exit;

class Endpoint {

    /**
     * Legacy → canonical user meta mapping used by the live theme/templates.
     * We write to these keys so My Profile / Edit Profile pages remain stable.
     */
    private static $map = [
        // core identity / bio
        'nationality' => 'nationality',    // (legacy key)
        'position'    => 'position',       // array or string
        'height'      => 'height',         // cm
        'weight'      => 'weight',         // kg
        'level'       => 'level',          // text
        'league'      => 'league',         // text
        'profile'     => 'profile',        // textarea
        // experience (periods/clubs)
        'period_1'    => 'period',
        'club_1'      => 'club',
        'period_2'    => 'period_2',
        'club_2'      => 'club_2',
        'period_3'    => 'period_3',
        'club_3'      => 'club_3',
        // media
        'link'        => 'link',           // primary video link (for iframe in template)
        'v_links'     => 'v_links',        // comma-separated list of links
        'v_upload_id' => 'v_upload_id',    // attachment id for uploaded mp4
        'upload_photo'=> 'upload_photo',   // attachment id for profile photo
        // consents
        '_oval15_sole_rep'        => '_oval15_sole_rep',
        '_oval15_marketing_consent'=> '_oval15_marketing_consent',
    ];

    public static function init() {
        add_shortcode('oval15_complete_registration', [__CLASS__, 'render_shortcode']);
        add_action('template_redirect', [__CLASS__, 'maybe_handle_post']);
    }

    /**
     * Render the Complete Registration form after pay-first checkout.
     * Accepts order & key (or order-received) to bind user+order safely.
     */
    public static function render_shortcode($atts = []) {
        if (!function_exists('wc_get_order')) {
            return '<div class="woocommerce-error">WooCommerce is not available.</div>';
        }

        $order_id  = isset($_GET['order']) ? absint($_GET['order']) : 0;
        if (!$order_id && isset($_GET['order-received'])) {
            $order_id = absint($_GET['order-received']);
        }
        $order_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';

        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order || ($order_key && $order->get_order_key() !== $order_key)) {
            return '<div class="woocommerce-error">Invalid or missing order.</div>';
        }

        // Require a billing email to create/attach account
        $billing_email = trim((string)$order->get_billing_email());
        if (!is_email($billing_email)) {
            return '<div class="woocommerce-error">This order has no billing email; please contact support.</div>';
        }

        // Ensure user exists; create a pending user if needed
        $user_id = (int) $order->get_user_id();
        if ($user_id <= 0) {
            $username = self::unique_username_from_email($billing_email);
            $password = wp_generate_password(18, true, true);
            $user_id  = wp_create_user($username, $password, $billing_email);
            if (is_wp_error($user_id)) {
                return '<div class="woocommerce-error">Could not create your account. Please contact support.</div>';
            }
            // Mark as "pending approval"
            update_user_meta($user_id, '_oval15_approved', 'no');
            $order->set_customer_id($user_id);
            $order->save();
        }

        // If POST happened and succeeded, show success
        if (isset($_GET['oval15_complete']) && $_GET['oval15_complete'] == '1') {
            return '<div class="woocommerce-message">Thanks! Your registration has been submitted. You can edit your profile from your account page.</div>';
        }

        // Preload existing values so user can confirm/edit
        $vals = self::current_values($user_id);

        // Allowed video hosts (from settings)
        $opt   = get_option(Settings::OPTION, []);
        $hosts = [];
        if (is_array($opt) && !empty($opt['video_hosts'])) {
            $hosts = array_filter(array_map('trim', explode(',', $opt['video_hosts'])));
        }
        $allow_upload = is_array($opt) ? !empty($opt['video_uploads']) : false;
        $max_mb       = is_array($opt) ? (int)($opt['video_max_mb'] ?? 100) : 100;

        ob_start();
        ?>
        <form class="oval15-complete-registration" method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('oval15_complete_reg_'.$user_id, '_wpnonce'); ?>
            <input type="hidden" name="oval15_action" value="complete_registration">
            <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>">
            <input type="hidden" name="order_key" value="<?php echo esc_attr($order->get_order_key()); ?>">

            <h3>Personal</h3>
            <p class="form-row form-row-first"><label>Nationality (Country) <span class="required">*</span></label>
                <input type="text" class="input-text" name="nationality" value="<?php echo esc_attr($vals['nationality']); ?>" required>
            </p>
            <p class="form-row form-row-last"><label>Primary Position</label>
                <input type="text" class="input-text" name="position[]" value="<?php echo esc_attr(self::first_array($vals['position'])); ?>">
            </p>
            <p class="form-row form-row-first"><label>Secondary Positions (comma separated)</label>
                <input type="text" class="input-text" name="position_secondary" value="<?php echo esc_attr(self::secondary_as_text($vals['position'])); ?>">
            </p>
            <p class="form-row form-row-last"><label>Height (cm)</label>
                <input type="number" class="input-text" name="height" value="<?php echo esc_attr($vals['height']); ?>">
            </p>
            <p class="form-row form-row-first"><label>Weight (kg)</label>
                <input type="number" class="input-text" name="weight" value="<?php echo esc_attr($vals['weight']); ?>">
            </p>

            <h3>Experience</h3>
            <p class="form-row form-row-first"><label>Highest Level</label>
                <input type="text" class="input-text" name="level" value="<?php echo esc_attr($vals['level']); ?>">
            </p>
            <p class="form-row form-row-last"><label>League</label>
                <input type="text" class="input-text" name="league" value="<?php echo esc_attr($vals['league']); ?>">
            </p>

            <p class="form-row form-row-first"><label>Period 1</label>
                <input type="text" class="input-text" name="period_1" value="<?php echo esc_attr($vals['period_1']); ?>">
            </p>
            <p class="form-row form-row-last"><label>Club/Team 1</label>
                <input type="text" class="input-text" name="club_1" value="<?php echo esc_attr($vals['club_1']); ?>">
            </p>

            <p class="form-row form-row-first"><label>Period 2</label>
                <input type="text" class="input-text" name="period_2" value="<?php echo esc_attr($vals['period_2']); ?>">
            </p>
            <p class="form-row form-row-last"><label>Club/Team 2</label>
                <input type="text" class="input-text" name="club_2" value="<?php echo esc_attr($vals['club_2']); ?>">
            </p>

            <p class="form-row form-row-first"><label>Period 3</label>
                <input type="text" class="input-text" name="period_3" value="<?php echo esc_attr($vals['period_3']); ?>">
            </p>
            <p class="form-row form-row-last"><label>Club/Team 3</label>
                <input type="text" class="input-text" name="club_3" value="<?php echo esc_attr($vals['club_3']); ?>">
            </p>

            <h3>Media</h3>
            <p class="form-row form-row-first"><label>Primary Video Link (YouTube/Vimeo etc.) <span class="required">*</span></label>
                <input type="url" class="input-text" name="link" value="<?php echo esc_attr($vals['link']); ?>" required>
            </p>
            <p class="form-row form-row-last"><label>Additional Video Links (comma separated)</label>
                <input type="text" class="input-text" name="v_links" value="<?php echo esc_attr($vals['v_links']); ?>">
            </p>
            <?php if ($allow_upload): ?>
                <p class="form-row"><label>Upload MP4 (max <?php echo (int)$max_mb; ?>MB)</label>
                    <input type="file" name="v_upload" accept="video/mp4">
                </p>
            <?php endif; ?>
            <p class="form-row"><label>Upload Profile Photo (required)</label>
                <input type="file" name="upload_photo" accept="image/*">
            </p>

            <h3>Profile (50 words max)</h3>
            <p class="form-row"><textarea class="input-text" name="profile" rows="5" maxlength="600"><?php echo esc_textarea($vals['profile']); ?></textarea></p>

            <h3>Consents</h3>
            <p class="form-row">
                <label><input type="checkbox" name="oval15_sole_rep" value="1" <?php checked($vals['_oval15_sole_rep'], 'yes'); ?>> I confirm Oval15 is my sole representative.</label>
            </p>
            <p class="form-row">
                <label><input type="checkbox" name="marketing_consent" value="1" <?php checked($vals['_oval15_marketing_consent'], 'yes'); ?>> I agree to receive marketing updates.</label>
            </p>

            <p class="form-row">
                <button type="submit" class="button button-primary">Submit Registration</button>
            </p>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle POST to save user meta using the legacy keys.
     */
    public static function maybe_handle_post() {
        if (empty($_POST['oval15_action']) || $_POST['oval15_action'] !== 'complete_registration') return;

        $order_id  = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $order_key = isset($_POST['order_key']) ? sanitize_text_field($_POST['order_key']) : '';
        if (!function_exists('wc_get_order') || !$order_id) return;

        $order = wc_get_order($order_id);
        if (!$order) return;
        if ($order_key && $order->get_order_key() !== $order_key) return;

        $user_id = (int) $order->get_user_id();
        if ($user_id <= 0) return;

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'oval15_complete_reg_'.$user_id)) return;

        // ====== Collect & normalize inputs ======
        $nationality = sanitize_text_field($_POST['nationality'] ?? '');
        $profile     = wp_kses_post($_POST['profile'] ?? '');
        $link        = trim((string)($_POST['link'] ?? ''));
        $level       = sanitize_text_field($_POST['level'] ?? '');
        $league      = sanitize_text_field($_POST['league'] ?? '');
        $height      = sanitize_text_field($_POST['height'] ?? '');
        $weight      = sanitize_text_field($_POST['weight'] ?? '');
        $period_1    = sanitize_text_field($_POST['period_1'] ?? '');
        $club_1      = sanitize_text_field($_POST['club_1'] ?? '');
        $period_2    = sanitize_text_field($_POST['period_2'] ?? '');
        $club_2      = sanitize_text_field($_POST['club_2'] ?? '');
        $period_3    = sanitize_text_field($_POST['period_3'] ?? '');
        $club_3      = sanitize_text_field($_POST['club_3'] ?? '');

        // Positions: primary from position[], secondary from comma text
        $pos_primary = isset($_POST['position']) ? (array) $_POST['position'] : [];
        $pos_primary = array_values(array_filter(array_map('sanitize_text_field', $pos_primary)));
        $pos_secondary = array_filter(array_map('trim', explode(',', (string)($_POST['position_secondary'] ?? ''))));
        $pos_secondary = array_values(array_filter(array_map('sanitize_text_field', $pos_secondary)));
        $positions = array_values(array_filter(array_merge($pos_primary, $pos_secondary)));

        // Validations aligned to your front-end template expectations
        $errors = [];
        if ($nationality === '') $errors[] = 'Country is required.';
        if ($club_1 === '' && $club_2 === '' && $club_3 === '') $errors[] = 'Club is required.';
        if ($period_1 === '' && $period_2 === '' && $period_3 === '') $errors[] = 'Tournament/Period is required.';
        if ($profile === '') $errors[] = 'Profile is required.';
        if ($link === '' || !filter_var($link, FILTER_VALIDATE_URL)) $errors[] = 'Valid Link is required.';

        // Handle profile photo upload (required)
        $existing_photo = (int) get_user_meta($user_id, 'upload_photo', true);
        $photo_id = $existing_photo;
        if (!empty($_FILES['upload_photo']['name'])) {
            $photo_id = self::handle_image_upload($_FILES['upload_photo'], ['jpg','jpeg','png','gif','webp']);
            if (is_wp_error($photo_id)) $errors[] = $photo_id->get_error_message();
        } elseif (!$existing_photo) {
            $errors[] = 'Photo is required.';
        }

        if (!empty($errors)) {
            // Surface errors as Woo notices and bounce back to form
            foreach ($errors as $e) {
                if (function_exists('wc_add_notice')) wc_add_notice($e, 'error');
            }
            wp_safe_redirect(add_query_arg([
                'order' => $order->get_id(),
                'key'   => $order->get_order_key(),
            ], get_permalink()));
            exit;
        }

        // ====== Save using legacy keys ======
        update_user_meta($user_id, 'nationality', $nationality);
        update_user_meta($user_id, 'profile', $profile);
        update_user_meta($user_id, 'level', $level);
        update_user_meta($user_id, 'league', $league);
        update_user_meta($user_id, 'height', $height);
        update_user_meta($user_id, 'weight', $weight);

        update_user_meta($user_id, 'period',   $period_1);
        update_user_meta($user_id, 'club',     $club_1);
        update_user_meta($user_id, 'period_2', $period_2);
        update_user_meta($user_id, 'club_2',   $club_2);
        update_user_meta($user_id, 'period_3', $period_3);
        update_user_meta($user_id, 'club_3',   $club_3);

        // positions can be stored as array in legacy
        if (!empty($positions)) {
            update_user_meta($user_id, 'position', $positions);
        } else {
            delete_user_meta($user_id, 'position');
        }

        // videos
        $opt   = get_option(Settings::OPTION, []);
        $hosts = [];
        if (is_array($opt) && !empty($opt['video_hosts'])) {
            $hosts = array_filter(array_map('trim', explode(',', $opt['video_hosts'])));
        }

        // Primary link (required)
        if ($hosts && !Video::host_allowed($link, $hosts)) {
            // If disallowed, keep but warn — don’t hard fail to preserve momentum
            if (function_exists('wc_add_notice')) wc_add_notice('Primary video link host is not in the allowed list.', 'notice');
        }
        update_user_meta($user_id, 'link', esc_url_raw($link));

        // Additional links (optional)
        $links_in = array_filter(array_map('trim', explode(',', (string)($_POST['v_links'] ?? ''))));
        $links_ok = [];
        foreach ($links_in as $u) {
            if (!filter_var($u, FILTER_VALIDATE_URL)) continue;
            if ($hosts && !Video::host_allowed($u, $hosts)) continue;
            $links_ok[] = esc_url_raw($u);
        }
        if (!empty($links_ok)) {
            update_user_meta($user_id, 'v_links', implode(',', $links_ok));
        } else {
            delete_user_meta($user_id, 'v_links');
        }

        // Optional MP4 upload
        $allow_upload = is_array($opt) ? !empty($opt['video_uploads']) : false;
        if ($allow_upload && !empty($_FILES['v_upload']['name'])) {
            $max_mb   = is_array($opt) ? (int)($opt['video_max_mb'] ?? 100) : 100;
            $attach_id = Video::handle_upload($_FILES['v_upload'], $max_mb);
            if (!is_wp_error($attach_id)) {
                update_user_meta($user_id, 'v_upload_id', (int)$attach_id);
            } else {
                if (function_exists('wc_add_notice')) wc_add_notice($attach_id->get_error_message(), 'error');
            }
        }

        // profile photo (required above)
        if ($photo_id) {
            update_user_meta($user_id, 'upload_photo', (int)$photo_id);
        }

        // consents
        update_user_meta($user_id, '_oval15_sole_rep', !empty($_POST['oval15_sole_rep']) ? 'yes' : '');
        update_user_meta($user_id, '_oval15_marketing_consent', !empty($_POST['marketing_consent']) ? 'yes' : '');

        // mark registration touched (for admin)
        update_user_meta($user_id, '_oval15_reg_completed_at', current_time('mysql'));

        // redirect to success state
        $redirect = add_query_arg([
            'order'            => $order->get_id(),
            'key'              => $order->get_order_key(),
            'oval15_complete'  => 1,
        ], get_permalink());
        wp_safe_redirect($redirect);
        exit;
    }

    private static function handle_image_upload(array $file, array $ext_whitelist = []) {
        if (!function_exists('wp_handle_upload')) require_once ABSPATH . 'wp-admin/includes/file.php';
        if (!function_exists('media_handle_sideload')) require_once ABSPATH . 'wp-admin/includes/media.php';
        if (!function_exists('wp_read_image_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';

        $name = isset($file['name']) ? (string)$file['name'] : '';
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext_whitelist && !in_array($ext, $ext_whitelist, true)) {
            return new \WP_Error('oval15_invalid_image', __('Unsupported image type.', 'oval15-core'));
        }

        // Sideload into media library
        $overrides = ['test_form' => false];
        $move = wp_handle_upload($file, $overrides);
        if (isset($move['error'])) {
            return new \WP_Error('oval15_upload_error', $move['error']);
        }

        $url  = $move['url'];
        $type = $move['type'];
        $file = $move['file'];

        $attachment = [
            'post_mime_type' => $type,
            'post_title'     => sanitize_file_name(basename($file)),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];

        $attach_id = wp_insert_attachment($attachment, $file);
        if (is_wp_error($attach_id)) return $attach_id;

        // Generate attachment metadata
        if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata($attach_id, $file);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }

    private static function current_values($user_id) {
        $out = [];
        foreach (self::$map as $form => $meta) {
            $out[$form] = get_user_meta($user_id, $meta, true);
        }

        // Normalize
        if (!is_array($out['position'])) {
            $out['position'] = $out['position'] ? (array)$out['position'] : [];
        }
        $out['period_1'] = (string)$out['period_1'];
        $out['period_2'] = (string)$out['period_2'];
        $out['period_3'] = (string)$out['period_3'];
        $out['club_1']   = (string)$out['club_1'];
        $out['club_2']   = (string)$out['club_2'];
        $out['club_3']   = (string)$out['club_3'];

        return $out;
    }

    private static function first_array($maybe_array) {
        if (is_array($maybe_array) && !empty($maybe_array)) return (string)$maybe_array[0];
        return '';
    }

    private static function secondary_as_text($positions) {
        if (!is_array($positions)) return '';
        $copy = $positions;
        if (!empty($copy)) array_shift($copy);
        return implode(', ', $copy);
    }

    private static function unique_username_from_email($email) {
        $base = sanitize_user(current(explode('@', $email)), true);
        if ($base === '') $base = 'user';
        $username = $base;
        $i = 1;
        while (username_exists($username)) {
            $username = $base . $i;
            $i++;
            if ($i > 9999) { $username = $base . wp_generate_password(4, false, false); break; }
        }
        return $username;
    }
}
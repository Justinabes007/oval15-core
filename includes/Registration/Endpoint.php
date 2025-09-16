<?php
namespace Oval15\Core\Registration;

use Oval15\Core\Admin\Settings;
use Oval15\Core\Media\Video;

if (!defined('ABSPATH')) exit;

class Endpoint {

    public static function init() {
        add_shortcode('oval15_complete_registration', [__CLASS__, 'render_shortcode']);
    }

    /* -------------------------------------------------------------
     * Shortcode entry
     * ------------------------------------------------------------- */
    public static function render_shortcode($atts = []) {
        // Ensure WooCommerce exists
        if (!function_exists('wc_get_order')) {
            return '<div class="woocommerce-error">WooCommerce is not available.</div>';
        }

        // Validate query params
        $order_id = isset($_GET['order']) ? absint($_GET['order']) : 0;
        $order_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';

        if (!$order_id || !$order_key) {
            return '<div class="woocommerce-error">Invalid registration link. Missing order information.</div>';
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return '<div class="woocommerce-error">Invalid registration link. Order not found.</div>';
        }

        if ($order->get_order_key() !== $order_key) {
            return '<div class="woocommerce-error">Invalid registration link. Order key mismatch.</div>';
        }

        // Sanity on status (paid orders are usually 'processing' or 'completed')
        $status = $order->get_status();
        $allowed_statuses = apply_filters('oval15/complete_reg_allowed_statuses', ['processing','completed']);
        if (!in_array($status, $allowed_statuses, true)) {
            return '<div class="woocommerce-info">Your order is currently <strong>'.esc_html($status).'</strong>. Once payment is confirmed, you can complete your registration.</div>';
        }

        // Prevent duplicate completion
        $done_user = (int) $order->get_meta('_oval15_registration_user');
        if ($done_user > 0) {
            $account = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
            return self::intro_html($order) .
                   '<div class="woocommerce-message">Registration was already completed for this order.</div>' .
                   '<p><a class="button" href="'.esc_url($account).'">Go to My Account</a></p>';
        }

        // Handle POST
        $notice = '';
        if (!empty($_POST['oval15_do_registration'])) {
            $resp = self::handle_submit($order);
            if (is_wp_error($resp)) {
                $notice = '<div class="woocommerce-error">'.esc_html($resp->get_error_message()).'</div>';
            } else {
                // Success screen
                $account = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
                $opt = get_option(Settings::OPTION, []);
                $sla = is_array($opt) ? (int)($opt['sla_hours'] ?? 48) : 48;
                $support = is_array($opt) ? ($opt['support_email'] ?? get_option('admin_email')) : get_option('admin_email');

                $html  = self::intro_html($order);
                $html .= '<div class="woocommerce-message">Thanks — your profile was submitted.</div>';
                $html .= '<div class="oval15-next-steps" style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f8fafc;margin:16px 0">';
                $html .= '<h3 style="margin-top:0">What happens next?</h3>';
                $html .= '<ol style="margin-left:18px">';
                $html .= '<li>Our team will review your details (target: '.$sla.' hours).</li>';
                $html .= '<li>You’ll receive a <strong>“Welcome to Oval15”</strong> email if approved.</li>';
                $html .= '<li>You can update your profile any time from <a href="'.esc_url($account).'">My Account</a>.</li>';
                $html .= '</ol>';
                $html .= '<p>Need help? Contact <a href="mailto:'.esc_attr($support).'">'.esc_html($support).'</a>.</p>';
                $html .= '</div>';
                $html .= '<p><a class="button" href="'.esc_url($account).'">Go to My Account</a></p>';

                return $html;
            }
        }

        // Render form
        return $notice . self::intro_html($order) . self::form_html($order);
    }

    /* -------------------------------------------------------------
     * Intro block (tokenized, editable in settings)
     * ------------------------------------------------------------- */
    private static function intro_html(\WC_Order $order) {
        $opt = get_option(Settings::OPTION, []);
        $intro = is_array($opt) ? ($opt['intro_html'] ?? '') : '';

        // Build tokens
        $items = [];
        foreach ($order->get_items() as $it) {
            $items[] = $it->get_name().' × '.$it->get_quantity();
        }
        $product_list = $items ? implode(', ', $items) : '';
        $total = function_exists('wc_price') ? wc_price($order->get_total(), ['currency'=>$order->get_currency()]) : ( $order->get_total().' '.$order->get_currency() );
        $sla = is_array($opt) ? (int)($opt['sla_hours'] ?? 48) : 48;
        $support = is_array($opt) ? ($opt['support_email'] ?? get_option('admin_email')) : get_option('admin_email');

        $tokens = [
            '{order_number}'  => (string) $order->get_order_number(),
            '{billing_email}' => esc_html( $order->get_billing_email() ),
            '{product_list}'  => esc_html( $product_list ),
            '{total}'         => $total,
            '{sla_hours}'     => (string) $sla,
            '{support_email}' => esc_html( $support ),
        ];

        if (!$intro) {
            $intro = '<h2>Thanks for your order #{order_number}</h2>
                      <p>We’ve received your payment (<strong>{total}</strong>) for: {product_list}.</p>
                      <p>Please complete your player registration below so we can review your profile (usually within {sla_hours} hours). If you need help, email {support_email}.</p>';
        }

        $html = strtr($intro, $tokens);
        return '<div class="oval15-intro" style="margin-bottom:16px">'.wp_kses_post(wpautop($html)).'</div>';
    }

    /* -------------------------------------------------------------
     * Form render
     * ------------------------------------------------------------- */
    private static function form_html(\WC_Order $order) {
        $billing_email = $order->get_billing_email();
        $opt = get_option(Settings::OPTION, []);
        $allow_upload = is_array($opt) ? !empty($opt['video_uploads']) : false;

        ob_start(); ?>
        <form method="post" enctype="multipart/form-data" class="woocommerce-EditAccountForm edit-account oval15-complete-registration">

            <?php wp_nonce_field('oval15_complete_registration', '_oval15_nonce'); ?>
            <input type="hidden" name="oval15_do_registration" value="1">

            <?php if (!is_user_logged_in()) : ?>
                <div class="oval15-panel" style="background:#f7f7f9;padding:12px;border:1px solid #e5e7eb;border-radius:8px;margin:12px 0">
                    <strong>Account email:</strong> <?php echo esc_html($billing_email); ?><br>
                    <small>We’ll create your account with this email.</small>
                </div>

                <p class="form-row form-row-first">
                    <label>Set a password&nbsp;<span class="required">*</span></label>
                    <input type="password" class="input-text" name="acct_pass1" required>
                </p>
                <p class="form-row form-row-last">
                    <label>Confirm password&nbsp;<span class="required">*</span></label>
                    <input type="password" class="input-text" name="acct_pass2" required>
                </p>
                <div class="clear"></div>
            <?php endif; ?>

            <h3>Personal Details</h3>
            <p class="form-row form-row-first">
                <label>First name&nbsp;<span class="required">*</span></label>
                <input type="text" class="input-text" name="first_name" required>
            </p>
            <p class="form-row form-row-last">
                <label>Last name&nbsp;<span class="required">*</span></label>
                <input type="text" class="input-text" name="last_name" required>
            </p>
            <p class="form-row form-row-first">
                <label>Contact number</label>
                <input type="tel" class="input-text" name="contact_number">
            </p>
            <p class="form-row form-row-last">
                <label>Date of Birth</label>
                <input type="date" class="input-text" name="dob">
            </p>
            <p class="form-row form-row-first">
                <label>Gender</label>
                <select name="gender">
                    <option value="">Select…</option>
                    <option>Male</option>
                    <option>Female</option>
                    <option>Other</option>
                </select>
            </p>
            <p class="form-row form-row-last">
                <label>Nationality</label>
                <input type="text" class="input-text" name="nation">
            </p>
            <p class="form-row form-row-first">
                <label>Current Country</label>
                <input type="text" class="input-text" name="current_location_country">
            </p>
            <p class="form-row form-row-last">
                <label>Height (cm)</label>
                <input type="number" class="input-text" name="height" step="1" min="0">
            </p>
            <p class="form-row form-row-first">
                <label>Weight (kg)</label>
                <input type="number" class="input-text" name="weight" step="0.1" min="0">
            </p>
            <p class="form-row form-row-last">
                <label>Years playing</label>
                <input type="number" class="input-text" name="years" step="1" min="0">
            </p>
            <p class="form-row form-row-first">
                <label>Months into current year</label>
                <input type="number" class="input-text" name="months" step="1" min="0" max="12">
            </p>

            <h3>Positions</h3>
            <p class="form-row">
                <label>Main Position</label>
                <select name="main-position">
                    <?php
                    $opts = ['','Prop (1)','Hooker','Prop (3)','Lock (4)','Lock (5)','Flank (Openside)','Flank (Blindside)','No 8','Scrumhalf','Flyhalf','Inside Centre (12)','Outside Centre (13)','Wing','Fullback','Utility Back'];
                    foreach ($opts as $o) {
                        echo '<option value="'.esc_attr($o).'">'.esc_html($o ?: 'Select…').'</option>';
                    }
                    ?>
                </select>
            </p>
            <p class="form-row">
                <label>Secondary Positions (Ctrl/Cmd to select multiple)</label>
                <select name="secondary-position[]" multiple size="6">
                    <?php foreach ($opts as $o) { if ($o==='') continue;
                        echo '<option value="'.esc_attr($o).'">'.esc_html($o).'</option>';
                    } ?>
                </select>
            </p>

            <h3>Experience</h3>
            <p class="form-row form-row-first"><label>Highest Level Played</label><input type="text" class="input-text" name="level"></p>
            <p class="form-row form-row-last"><label>League</label><input type="text" class="input-text" name="league"></p>
            <p class="form-row form-row-first"><label>Period 1</label><input type="text" class="input-text" name="period_1"></p>
            <p class="form-row form-row-last"><label>Club/Team 1</label><input type="text" class="input-text" name="club_1"></p>
            <p class="form-row form-row-first"><label>Period 2</label><input type="text" class="input-text" name="period_2"></p>
            <p class="form-row form-row-last"><label>Club/Team 2</label><input type="text" class="input-text" name="club_2"></p>
            <p class="form-row form-row-first"><label>Period 3</label><input type="text" class="input-text" name="period_3"></p>
            <p class="form-row form-row-last"><label>Club/Team 3</label><input type="text" class="input-text" name="club_3"></p>
            <p class="form-row"><label>Reason for leaving last club</label><input type="text" class="input-text" name="reason"></p>

            <h3>Tournaments</h3>
            <p class="form-row form-row-first"><label>Tournament 1</label><input type="text" class="input-text" name="tournament_1"></p>
            <p class="form-row form-row-last"><label>Tournament 2</label><input type="text" class="input-text" name="tournament_2"></p>
            <p class="form-row form-row-first"><label>Tournament 3</label><input type="text" class="input-text" name="tournament_3"></p>

            <h3>International Interest & Passports</h3>
            <p class="form-row"><label>Interested Countries (up to 3)</label>
                <input type="text" class="input-text" name="interested_country[]" placeholder="Country 1">
                <input type="text" class="input-text" name="interested_country[]" placeholder="Country 2">
                <input type="text" class="input-text" name="interested_country[]" placeholder="Country 3">
            </p>
            <p class="form-row"><label>Passport(s)</label>
                <input type="text" class="input-text" name="passport[]" placeholder="e.g. South African">
                <input type="text" class="input-text" name="passport[]" placeholder="e.g. British">
            </p>

            <h3>Media</h3>
            <?php
            $hosts_hint = '';
            $opt = get_option(Settings::OPTION, []);
            if (is_array($opt) && !empty($opt['video_hosts'])) {
                $hosts_hint = ' Allowed hosts: '.esc_html($opt['video_hosts']).'.';
            }
            ?>
            <p class="form-row"><label>Video links (YouTube/Vimeo; up to 3)</label>
                <input type="url" class="input-text" name="v_links[]" placeholder="https://" >
                <input type="url" class="input-text" name="v_links[]" placeholder="https://" >
                <input type="url" class="input-text" name="v_links[]" placeholder="https://" >
                <small><?php echo $hosts_hint; ?></small>
            </p>
            <?php if ($allow_upload) : ?>
            <p class="form-row"><label>Upload highlight clip (mp4/webm/mov)</label>
                <input type="file" name="v_upload" accept="video/mp4,video/webm,video/quicktime">
            </p>
            <?php endif; ?>

            <h3>Strengths & Achievements</h3>
            <p class="form-row">
                <label>Strengths of game &amp; Rugby Achievements (max 50 words)</label>
                <textarea name="p_profile" rows="4" maxlength="800" placeholder="Max 50 words"></textarea>
            </p>

            <p class="form-row">
                <label><input type="checkbox" name="oval15_sole_rep" value="yes"> I confirm Oval15 is my sole representative</label>
            </p>
            <p class="form-row">
                <label><input type="checkbox" name="marketing_consent" value="yes"> I agree to receive occasional updates</label>
            </p>

            <p class="form-row"><button type="submit" class="button alt">Submit Registration</button></p>
        </form>
        <?php
        return ob_get_clean();
    }

    /* -------------------------------------------------------------
     * POST handler
     * ------------------------------------------------------------- */
    private static function handle_submit(\WC_Order $order) {
        if (!isset($_POST['_oval15_nonce']) || !wp_verify_nonce($_POST['_oval15_nonce'], 'oval15_complete_registration')) {
            return new \WP_Error('oval15_nonce', 'Security check failed.');
        }

        $billing_email = $order->get_billing_email();
        if (!is_email($billing_email)) {
            return new \WP_Error('oval15_email', 'Your order is missing a valid billing email.');
        }

        // Account logic
        $user_id = get_current_user_id();
        if (!$user_id) {
            // If an account already exists for billing email, require login to prevent hijack
            $existing = get_user_by('email', $billing_email);
            if ($existing) {
                $login = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url();
                return new \WP_Error('oval15_login_required', 'An account already exists for '.$billing_email.'. Please log in to continue: '.$login);
            }

            $p1 = isset($_POST['acct_pass1']) ? (string) $_POST['acct_pass1'] : '';
            $p2 = isset($_POST['acct_pass2']) ? (string) $_POST['acct_pass2'] : '';
            if ($p1 === '' || $p2 === '' || $p1 !== $p2) {
                return new \WP_Error('oval15_password', 'Please set and confirm your password.');
            }

            $username = self::unique_username_from_email($billing_email);
            $user_id = wp_insert_user([
                'user_login' => $username,
                'user_email' => $billing_email,
                'user_pass'  => $p1,
                'role'       => 'customer',
            ]);
            if (is_wp_error($user_id)) {
                return new \WP_Error('oval15_user', 'Could not create your account: '.$user_id->get_error_message());
            }

            // Log them in
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true);
        }

        // Required fields
        $first = sanitize_text_field($_POST['first_name'] ?? '');
        $last  = sanitize_text_field($_POST['last_name'] ?? '');
        if ($first === '' || $last === '') {
            return new \WP_Error('oval15_required', 'Please complete all required fields (first & last name).');
        }

        // 50-word cap
        if (!empty($_POST['p_profile'])) {
            $words = preg_split('/\s+/', trim(wp_strip_all_tags((string) $_POST['p_profile'])));
            if (count($words) > 50) {
                return new \WP_Error('oval15_profile_words', 'Please keep “Strengths & Achievements” to 50 words or fewer.');
            }
        }

        // Save core user meta
        update_user_meta($user_id, 'first_name', $first);
        update_user_meta($user_id, 'last_name',  $last);

        // Scalars
        $map = [
            'contact_number','dob','p_profile','nation','current_location_country','gender',
            'height','weight','years','months','level','league',
            'period_1','club_1','period_2','club_2','period_3','club_3','reason',
            'tournament_1','tournament_2','tournament_3','main-position'
        ];
        foreach ($map as $k) {
            if (isset($_POST[$k])) {
                update_user_meta($user_id, $k, sanitize_text_field((string) $_POST[$k]));
            }
        }

        // Arrays → CSV
        if (isset($_POST['secondary-position'])) {
            $arr = array_map('sanitize_text_field', (array) $_POST['secondary-position']);
            update_user_meta($user_id, 'secondary-position', implode(',', $arr));
        }
        if (isset($_POST['interested_country'])) {
            $arr = array_filter(array_map('sanitize_text_field', (array) $_POST['interested_country']));
            update_user_meta($user_id, 'interested_country', implode(',', array_slice($arr, 0, 3)));
        }
        if (isset($_POST['passport'])) {
            $arr = array_filter(array_map('sanitize_text_field', (array) $_POST['passport']));
            update_user_meta($user_id, 'passport', implode(',', $arr));
        }

        // Flags
        update_user_meta($user_id, '_oval15_sole_rep', !empty($_POST['oval15_sole_rep']) ? 'yes' : '');
        update_user_meta($user_id, '_oval15_marketing_consent', !empty($_POST['marketing_consent']) ? 'yes' : '');

        // Video links + optional upload
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
        if (!empty($links_ok)) {
            update_user_meta($user_id, 'v_links', implode(',', $links_ok));
            update_user_meta($user_id, 'v_link', $links_ok[0]); // legacy primary
        } else {
            delete_user_meta($user_id, 'v_links');
        }

        $allow_upload = is_array($opt) ? !empty($opt['video_uploads']) : false;
        if ($allow_upload && !empty($_FILES['v_upload']['name'])) {
            $max_mb = is_array($opt) ? (int)($opt['video_max_mb'] ?? 100) : 100;
            $attach_id = Video::handle_upload($_FILES['v_upload'], $max_mb);
            if (is_wp_error($attach_id)) return $attach_id;
            update_user_meta($user_id, 'v_upload_id', (int) $attach_id);
        }

        // Link order to customer and mark complete-registration done
        if (!$order->get_user_id()) {
            $order->set_customer_id($user_id);
        }
        $order->update_meta_data('_oval15_registration_user', $user_id);
        $order->update_meta_data('_oval15_registration_completed', current_time('mysql'));
        $order->save();

        // Ensure approval status default
        if (!get_user_meta($user_id, '_oval15_approved', true)) {
            update_user_meta($user_id, '_oval15_approved', 'no');
        }

        // Emit event for integrations
        do_action('oval15/registration_completed', $user_id, $order->get_id());

        return true;
    }

    /* -------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */
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
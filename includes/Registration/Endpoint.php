<?php
namespace Oval15\Core\Registration;

use Oval15\Core\Admin\Settings;

if (!defined('ABSPATH')) exit;

class Endpoint {
    public static function init() {
        add_shortcode('oval15_complete_registration', [__CLASS__, 'render_shortcode']);
    }

    public static function render_shortcode() {
        $order_id = isset($_GET['order']) ? absint($_GET['order']) : 0;
        $key      = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';

        // Friendly fallback if link is missing
        if (!$order_id || !$key) {
            return self::finder_form();
        }

        if (!function_exists('wc_get_order')) return '<p>WooCommerce is required.</p>';
        $order = wc_get_order($order_id);
        if (!$order) return '<p>Order not found.</p>';

        $token = $order->get_meta('_oval15_reg_token');
        if (!$token || !hash_equals($token, $key)) {
            return '<p>Registration link invalid or expired.</p>';
        }

        // Handle submission
        if (!empty($_POST['oval15_do_complete_reg']) && function_exists('check_admin_referer') && check_admin_referer('oval15_complete_reg', '_oval15_nonce')) {
            $resp = self::handle_submit($order);
            if (is_wp_error($resp)) {
                return '<div class="woocommerce-error">' . esc_html($resp->get_error_message()) . '</div>' . self::intro_html($order) . self::form_html();
            }
            // Success → next page (can tweak later)
            wp_safe_redirect(home_url('/profile-setup/')); exit;
        }

        return self::intro_html($order) . self::form_html();
    }

    private static function intro_html($order) {
        $opt = get_option(Settings::OPTION, []);
        $sla = !empty($opt['sla_hours']) ? absint($opt['sla_hours']) : 48;
        $support = !empty($opt['support_email']) ? sanitize_email($opt['support_email']) : get_option('admin_email');

        $order_number = $order->get_order_number();
        $billing_email = $order->get_billing_email();

        $items = [];
        foreach ($order->get_items() as $it) { $items[] = $it->get_name(); }
        $product_list = implode(', ', $items);
        $total = function_exists('wc_price') ? wc_price($order->get_total()) : number_format((float)$order->get_total(), 2);

        $tokens = [
            '{order_number}'  => esc_html($order_number),
            '{billing_email}' => esc_html($billing_email),
            '{product_list}'  => esc_html($product_list),
            '{total}'         => esc_html(strip_tags($total)),
            '{sla_hours}'     => esc_html((string) $sla),
            '{support_email}' => esc_html($support),
        ];

        $custom = is_array($opt) ? ($opt['intro_html'] ?? '') : '';
        if ($custom) {
            $html = wp_kses_post(strtr($custom, $tokens));
        } else {
            $html  = '<div class="oval15-intro"><h2>Thanks for your order <span>#'.esc_html($order_number).'</span></h2>';
            $html .= '<p><strong>Package:</strong> '.esc_html($product_list).' &middot; <strong>Total:</strong> '.$total.' &middot; <strong>Email:</strong> '.esc_html($billing_email).'</p>';
            $html .= '<ol class="oval15-steps">';
            $html .= '<li><strong>Create your account:</strong> set a password and confirm your details below.</li>';
            $html .= '<li><strong>Complete your player profile:</strong> positions, experience, media & achievements (max 50 words).</li>';
            $html .= '<li><strong>Submit:</strong> click <em>Create Account</em>. You can update your profile later.</li>';
            $html .= '<li><strong>Approval:</strong> we review within ~'.esc_html((string)$sla).' hours. Look out for your “Welcome to Oval15” email.</li>';
            $html .= '</ol><p class="oval15-help">Need help? Email <a href="mailto:'.esc_attr($support).'">'.esc_html($support).'</a>.</p></div>';
        }

        $html .= '<style>.oval15-intro{background:#f7f7f9;padding:16px;border:1px solid #e5e7eb;border-radius:8px;margin:0 0 16px}.oval15-intro h2{margin:0 0 8px;font-size:1.25rem}.oval15-intro .oval15-steps{margin:8px 0 0 18px}.oval15-intro .oval15-help{margin-top:8px;font-size:.9rem;color:#555}</style>';
        return apply_filters('oval15/complete_registration_intro', $html, $order, $opt);
    }

    private static function form_html() {
        ob_start(); ?>
        <form method="post" enctype="multipart/form-data" class="woocommerce-form woocommerce-form-register register">
            <?php if (function_exists('wp_nonce_field')) wp_nonce_field('oval15_complete_reg', '_oval15_nonce'); ?>

            <h3>Personal Details</h3>
            <p class="form-row form-row-first"><label>First name&nbsp;<span class="required">*</span></label><input type="text" class="input-text" name="billing_first_name" required></p>
            <p class="form-row form-row-last"><label>Last name&nbsp;<span class="required">*</span></label><input type="text" class="input-text" name="billing_last_name" required></p>
            <p class="form-row form-row-first"><label>Contact number&nbsp;<span class="required">*</span></label><input type="tel" class="input-text" name="contact_number" required></p>
            <p class="form-row form-row-last"><label>Date of Birth&nbsp;<span class="required">*</span></label><input type="date" class="input-text" name="dob" required></p>
            <p class="form-row form-row-first"><label>Gender&nbsp;<span class="required">*</span></label>
                <select name="gender" required><option value="">Select…</option><option>Male</option><option>Female</option><option>Other</option></select>
            </p>
            <p class="form-row form-row-last"><label>Nationality</label><input type="text" class="input-text" name="nation"></p>
            <p class="form-row form-row-first"><label>Current Country</label><input type="text" class="input-text" name="current_location_country"></p>
            <p class="form-row form-row-last"><label>Height (cm)</label><input type="number" class="input-text" name="height" step="1" min="0"></p>
            <p class="form-row form-row-first"><label>Weight (kg)</label><input type="number" class="input-text" name="weight" step="0.1" min="0"></p>
            <p class="form-row form-row-last"><label>Years playing</label><input type="number" class="input-text" name="years" step="1" min="0"></p>
            <p class="form-row form-row-first"><label>Months into current year</label><input type="number" class="input-text" name="months" step="1" min="0" max="12"></p>

            <h3>Positions</h3>
            <p class="form-row"><label>Main Position&nbsp;<span class="required">*</span></label>
                <select name="main-position" required>
                    <option value="">Select…</option><option>Prop (1)</option><option>Hooker</option><option>Prop (3)</option><option>Lock (4)</option><option>Lock (5)</option><option>Flank (Openside)</option><option>Flank (Blindside)</option><option>No 8</option><option>Scrumhalf</option><option>Flyhalf</option><option>Inside Centre (12)</option><option>Outside Centre (13)</option><option>Wing</option><option>Fullback</option><option>Utility Back</option>
                </select>
            </p>
            <p class="form-row"><label>Secondary Positions (Ctrl/Cmd to select multiple)</label>
                <select name="secondary-position[]" multiple size="6">
                    <option>Prop (1)</option><option>Hooker</option><option>Prop (3)</option><option>Lock (4)</option><option>Lock (5)</option><option>Flank (Openside)</option><option>Flank (Blindside)</option><option>No 8</option><option>Scrumhalf</option><option>Flyhalf</option><option>Inside Centre (12)</option><option>Outside Centre (13)</option><option>Wing</option><option>Fullback</option><option>Utility Back</option>
                </select>
            </p>

            <h3>Experience</h3>
            <p class="form-row form-row-first"><label>Highest Level Played</label><input type="text" class="input-text" name="level" placeholder="e.g. School, Club, Provincial, National"></p>
            <p class="form-row form-row-last"><label>League</label><input type="text" class="input-text" name="league"></p>
            <p class="form-row form-row-first"><label>Period 1</label><input type="text" class="input-text" name="period_1" placeholder="e.g. 2023–2024"></p>
            <p class="form-row form-row-last"><label>Club/Team 1</label><input type="text" class="input-text" name="club_1"></p>
            <p class="form-row form-row-first"><label>Period 2</label><input type="text" class="input-text" name="period_2" placeholder="e.g. 2022–2023"></p>
            <p class="form-row form-row-last"><label>Club/Team 2</label><input type="text" class="input-text" name="club_2"></p>
            <p class="form-row form-row-first"><label>Period 3</label><input type="text" class="input-text" name="period_3" placeholder="e.g. 2021–2022"></p>
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
            <p class="form-row"><label>Video link (YouTube/Vimeo/other)</label><input type="url" class="input-text" name="v_link" placeholder="https://"></p>
            <p class="form-row"><label>Profile photo</label><input type="file" name="p_photo" accept="image/*"></p>

            <h3>Strengths & Achievements</h3>
            <p class="form-row"><label>Strengths of game &amp; Rugby Achievements (max 50 words)</label>
                <textarea name="p_profile" rows="4" maxlength="800" placeholder="Briefly describe your strengths & achievements (max 50 words)."></textarea>
            </p>

            <p class="form-row"><label><input type="checkbox" name="oval15_sole_rep" value="yes"> I confirm Oval15 is my sole representative</label></p>
            <p class="form-row"><label><input type="checkbox" name="marketing_consent" value="yes"> I agree to receive occasional updates from Oval15</label></p>
            <p class="form-row"><label><input type="checkbox" name="t_and_c" value="yes" required> I accept the Terms & Conditions</label></p>

            <p class="form-row"><button type="submit" class="button alt" name="oval15_do_complete_reg" value="1">Create Account</button></p>
        </form>
        <?php
        return ob_get_clean();
    }

    private static function handle_submit($order) {
        $email = $order->get_billing_email();
        $first = isset($_POST['billing_first_name']) ? sanitize_text_field($_POST['billing_first_name']) : '';
        $last  = isset($_POST['billing_last_name']) ? sanitize_text_field($_POST['billing_last_name']) : '';
        $pass  = isset($_POST['user_pass']) ? (string) $_POST['user_pass'] : '';

        if (!$first || !$last || !is_email($email) || !$pass) {
            return new \WP_Error('oval15_missing', 'Please complete all required fields (name + password).');
        }

        // 50-word cap
        if (!empty($_POST['p_profile'])) {
            $words = preg_split('/\s+/', trim(wp_strip_all_tags((string) $_POST['p_profile'])));
            if (count($words) > 50) {
                return new \WP_Error('oval15_profile_words', 'Please keep this section to 50 words or fewer.');
            }
        }

        $existing_user_id = (int) $order->get_user_id();
        if ($existing_user_id > 0) {
            $user_id = $existing_user_id;
            if (!empty($pass)) { wp_set_password($pass, $user_id); }
        } else {
            $user_id = username_exists($email);
            if (!$user_id) {
                $user_id = wp_create_user($email, $pass, $email);
                if (is_wp_error($user_id)) return $user_id;
                update_user_meta($user_id, 'first_name', $first);
                update_user_meta($user_id, 'last_name', $last);
                update_user_meta($user_id, '_oval15_approved', 'no');
                (new \WP_User($user_id))->set_role('subscriber');
            }
            $order->set_customer_id($user_id); $order->save();
            if (function_exists('wcs_get_subscriptions_for_order')) {
                $subs = wcs_get_subscriptions_for_order($order->get_id(), ['order_type' => 'any']);
                foreach ($subs as $sub) { $sub->set_customer_id($user_id); $sub->save(); }
            }
        }

        // Persist core fields (simple)
        $map = ['p_profile','v_link','nation','current_location_country','gender','height','weight','years','months','level','league','period_1','club_1','period_2','club_2','period_3','club_3','reason','tournament_1','tournament_2','tournament_3','main-position'];
        foreach ($map as $k) {
            if (isset($_POST[$k])) update_user_meta($user_id, $k, sanitize_text_field(is_array($_POST[$k]) ? implode(',', $_POST[$k]) : $_POST[$k]));
        }
        if (isset($_POST['secondary-position'])) update_user_meta($user_id, 'secondary-position', implode(',', array_map('sanitize_text_field', (array) $_POST['secondary-position'])));
        if (isset($_POST['interested_country'])) update_user_meta($user_id, 'interested_country', implode(',', array_map('sanitize_text_field', (array) $_POST['interested_country'])));
        if (isset($_POST['passport'])) update_user_meta($user_id, 'passport', implode(',', array_map('sanitize_text_field', (array) $_POST['passport'])));
        if (isset($_POST['oval15_sole_rep'])) update_user_meta($user_id, '_oval15_sole_rep', 'yes');
        if (isset($_POST['marketing_consent'])) update_user_meta($user_id, '_oval15_marketing_consent', 'yes');

        // Invalidate token
        $order->delete_meta_data('_oval15_reg_token'); $order->save();

        do_action('oval15/registration_completed', $user_id, $order->get_id());
        return true;
    }

    private static function finder_form() {
        ob_start(); ?>
        <form method="post" class="woocommerce-form woocommerce-form-register register">
            <?php if (function_exists('wp_nonce_field')) wp_nonce_field('oval15_find_link', '_oval15_find_nonce'); ?>
            <h3>Find your Complete Registration link</h3>
            <p>If you don’t have your link, enter your Order ID and billing email and we’ll email it to you.</p>
            <p class="form-row"><label>Order ID&nbsp;<span class="required">*</span></label><input type="number" class="input-text" name="find_order_id" required></p>
            <p class="form-row"><label>Billing email&nbsp;<span class="required">*</span></label><input type="email" class="input-text" name="find_email" required></p>
            <p class="form-row"><button type="submit" class="button" name="oval15_find_link" value="1">Email my link</button></p>
        </form>
        <?php return ob_get_clean();
    }
}
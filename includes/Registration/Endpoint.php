<?php
namespace Oval15\Core\Registration;

if (!defined('ABSPATH')) exit;

class Endpoint {

    public static function init() {
        add_shortcode('oval15_complete_registration', [__CLASS__, 'render_shortcode']);
        // Handle POST before any output -> avoids header warnings/blank pages
        add_action('template_redirect', [__CLASS__, 'maybe_handle_submit'], 0);
    }

    /** Early POST handler (PRG) */
    public static function maybe_handle_submit() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        if (empty($_POST['_oval15_nonce']) || !wp_verify_nonce($_POST['_oval15_nonce'], 'oval15_complete_reg')) return;
        if (empty($_POST['oval15_complete_submit'])) return;

        $order_id = isset($_POST['order']) ? absint($_POST['order']) : 0;
        $key      = isset($_POST['key'])   ? sanitize_text_field($_POST['key']) : '';

        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order) {
            self::redirect_with_errors($order_id, $key, [], ['Invalid or expired link. Please contact support.']);
        }

        // Token (custom reg token first, fallback to WC order key)
        $expected = (string) $order->get_meta('_oval15_reg_token');
        if (!$expected) $expected = (string) $order->get_order_key();
        if (!hash_equals((string) $expected, (string) $key)) {
            self::redirect_with_errors($order_id, $key, [], ['Invalid or expired link. Please contact support.']);
        }

        $user_id = (int) $order->get_user_id();
        if (!$user_id) {
            self::redirect_with_errors($order_id, $key, [], ['We could not associate this order with an account.']);
        }

        // Validate
        $P      = $_POST;
        $errors = self::validate($P, $user_id);

        // Photo required if none on file yet
        $has_photo = (int) get_user_meta($user_id,'upload_photo', true) > 0;
        if (!$has_photo && empty($_FILES['p_photo']['name'])) {
            $errors[] = 'Profile Pic is required.';
        }

        // Profile <= 200 words
        $profile_words = self::count_words(wp_strip_all_tags((string) ($P['p_profile'] ?? '')));
        if ($profile_words > 200) {
            $errors[] = 'Profile exceeds 200 words. Please shorten it.';
        }

        if (!empty($errors)) {
            self::redirect_with_errors($order_id, $key, $P, $errors);
        }

        // Persist
        self::persist($P, $user_id);

        // Flags + Webhook
        update_user_meta($user_id, '_oval15_status', 'pending');

        /**
         * IMPORTANT: Webhook expects **two** args (user_id, order_id).
         * This fixes: Too few arguments to function Webhooks::on_registration_completed()
         */
        do_action('oval15/registration_completed', $user_id, $order_id);

        // mark this order as completed-registration so GET can show success panel
        $order->update_meta_data('_oval15_reg_completed', 'yes');
        $order->update_meta_data('_oval15_reg_completed_at', current_time('mysql'));
        $order->save();

        // Redirect to GET
        $url = self::build_base_url($order_id, $key);
        $url = add_query_arg(['submitted' => '1'], $url);
        self::safe_redirect($url);
    }

    /** GET renderer */
    public static function render_shortcode() {
        $order_id  = isset($_GET['order']) ? absint($_GET['order']) : 0;
        $key       = isset($_GET['key'])   ? sanitize_text_field($_GET['key']) : '';
        $submitted = isset($_GET['submitted']) && $_GET['submitted'] === '1';
        $errors_q  = isset($_GET['errors']) && $_GET['errors'] === '1';

        if (!$order_id || !$key) return self::error_box('Invalid or expired link. Please contact support.');

        $order = wc_get_order($order_id);
        if (!$order) return self::error_box('Invalid or expired link. Please contact support.');

        // Validate token for display
        $expected = (string) $order->get_meta('_oval15_reg_token');
        if (!$expected) $expected = (string) $order->get_order_key();
        if (!hash_equals((string) $expected, (string) $key)) {
            return self::error_box('Invalid or expired link. Please contact support.');
        }

        $user_id        = (int) $order->get_user_id();
        $flag_completed = (string) $order->get_meta('_oval15_reg_completed') === 'yes';

        // Show success if redirected after submit OR order flagged complete
        if ($submitted || $flag_completed) {
            return self::order_panel($order) . self::success_panel($order);
        }

        // Re-render with errors if present
        $notices = '';
        $posted  = [];
        if ($errors_q) {
            $state = self::pull_state($order_id, $key, $user_id);
            if (is_array($state)) {
                if (!empty($state['errors'])) $notices = self::error_list($state['errors']);
                if (!empty($state['posted'])) $posted  = $state['posted'];
            }
        }

        return self::order_panel($order) . $notices . self::form_html($order_id, $key, $user_id, $posted);
    }

    /** Validation */
    private static function validate(array $P, int $user_id): array {
        $errors = [];
        $req = function($name, $label) use (&$P, &$errors) {
            if (!isset($P[$name]) || $P[$name] === '' || (is_array($P[$name]) && count(array_filter((array)$P[$name], fn($v) => $v !== ''))===0)) {
                $errors[] = sprintf('%s is required.', esc_html($label));
            }
        };

        // Singles
        $req('email', 'Email address');
        $req('billing_first_name', 'First name');
        $req('billing_last_name', 'Last name');
        $req('gender', 'Gender');
        $req('lop', 'Level of player');
        $req('contact_number', 'Contact number');
        $req('dob', 'Date of Birth');
        $req('main-position', 'Main Position');
        $req('nation', 'Nationality');
        $req('current_location_country', 'Current Location (Country)');
        $req('height', 'Height');
        $req('weight', 'Weight');
        $req('club_1', 'Club Name (current playing history)');
        $req('tournament_1', 'League (current playing history)');
        $req('period_1', 'Period (current playing history)');
        $req('p_profile', 'Profile');
        $req('v_link', 'Video Link');
        $req('months', 'Available From (Month)');
        $req('years', 'Available From (Year)');
        $req('reason', 'Availability reason');
        $req('t_and_c', 'Agree Terms and Conditions');

        // Arrays
        $req('passport', 'Passport');
        $req('level', 'Level looking for');
        $req('interested_country', 'Countries interested in playing in');

        return $errors;
    }

    /** Persist to user meta (formats tuned for theme) */
    private static function persist(array $P, int $user_id): void {
        $text  = fn($v) => sanitize_text_field((string) $v);
        $email = fn($v) => sanitize_email((string) $v);
        $url   = fn($v) => esc_url_raw((string) $v);

        // Names / email
        $first = $text($P['billing_first_name']); $last = $text($P['billing_last_name']);
        if ($first) { update_user_meta($user_id, 'first_name', $first); update_user_meta($user_id, 'billing_first_name', $first); }
        if ($last)  { update_user_meta($user_id, 'last_name',  $last);  update_user_meta($user_id, 'billing_last_name',  $last); }
        if (!empty($P['email'])) update_user_meta($user_id, 'email', $email($P['email']));

        // Gender / Level of player
        $gender = in_array(($P['gender'] ?? ''), ['Male','Female'], true) ? $P['gender'] : '';
        $lop    = in_array(($P['lop'] ?? ''),    ['Amateur','Semi-Pro','Professional'], true) ? $P['lop'] : '';
        update_user_meta($user_id, 'gender', $gender);
        update_user_meta($user_id, 'lop', $lop);                         // scalar (legacy)
        update_user_meta($user_id, 'level_of_player', $lop);             // scalar (theme now echoes; avoids "Array to string")
        update_user_meta($user_id, 'level_of_player_arr', $lop ? [$lop] : []); // optional array for future compatibility

        // Phone + dial code
        update_user_meta($user_id, 'contact_number', $text($P['contact_number']));
        if (!empty($P['country_code'])) update_user_meta($user_id, 'country_code', $text($P['country_code']));

        // DOB / height / weight
        $dob = $text($P['dob']);
        update_user_meta($user_id, 'dob', $dob);
        update_user_meta($user_id, 'date_of_birth', $dob);
        update_user_meta($user_id, 'height', $text($P['height']));
        update_user_meta($user_id, 'weight', $text($P['weight']));

        // Nationality / current location
        update_user_meta($user_id, 'nationality', $text($P['nation']));
        update_user_meta($user_id, 'current_location_country', $text($P['current_location_country']));

        // Positions: position as ARRAY (fixes implode fatal in template)
        $main_pos = $text($P['main-position']);
        update_user_meta($user_id, 'position',      $main_pos ? [$main_pos] : []); // array
        update_user_meta($user_id, 'main_position',  $main_pos);                    // string mirror
        update_user_meta($user_id, 'reg_position',   $main_pos);

        $secondary_arr = array_values(array_filter(array_map($text, (array) ($P['secondary-position'] ?? []))));
        update_user_meta($user_id, 'secondary_position', $secondary_arr);                 // array
        update_user_meta($user_id, 'secondary-position', implode(',', $secondary_arr));   // legacy CSV

        // Passports / interested / level (arrays + CSV mirrors)
        $passport_arr   = array_values(array_filter(array_map($text, (array) ($P['passport'] ?? []))));
        $interested_arr = array_values(array_filter(array_map($text, (array) ($P['interested_country'] ?? []))));
        $level_arr      = array_values(array_filter(array_map($text, (array) ($P['level'] ?? []))));
        update_user_meta($user_id, 'passport',            $passport_arr);
        update_user_meta($user_id, 'interested_country',  $interested_arr);
        update_user_meta($user_id, 'level',               $level_arr);
        update_user_meta($user_id, 'level_of_club',       implode(',', $level_arr)); // CSV mirror
        update_user_meta($user_id, 'level ',              implode(',', $level_arr)); // legacy key with trailing space

        // Playing history
        $club1 = $text($P['club_1']); $club2 = $text($P['club_2'] ?? ''); $club3 = $text($P['club_3'] ?? '');
        if ($club1 !== '') update_user_meta($user_id,'club',   $club1);
        if ($club2 !== '') update_user_meta($user_id,'club_2', $club2);
        if ($club3 !== '') update_user_meta($user_id,'club_3', $club3);

        $t1 = $text($P['tournament_1']); $t2 = $text($P['tournament_2'] ?? ''); $t3 = $text($P['tournament_3'] ?? '');
        if ($t1 !== '') update_user_meta($user_id,'tournament',   $t1);
        if ($t2 !== '') update_user_meta($user_id,'tournament_2', $t2);
        if ($t3 !== '') update_user_meta($user_id,'tournament_3', $t3);

        $per1 = $text($P['period_1']); $per2 = $text($P['period_2'] ?? ''); $per3 = $text($P['period_3'] ?? '');
        if ($per1 !== '') update_user_meta($user_id,'period',   $per1);
        if ($per2 !== '') update_user_meta($user_id,'period_2', $per2);
        if ($per3 !== '') update_user_meta($user_id,'period_3', $per3);

        // Bio
        if (isset($P['p_profile'])) {
            $bio = self::limit_words(wp_kses_post((string) $P['p_profile']), 200);
            update_user_meta($user_id, 'profile',   wp_strip_all_tags($bio));
            update_user_meta($user_id, 'p_profile', wp_strip_all_tags($bio));
        }

        // Video
        update_user_meta($user_id, 'link', $url($P['v_link']));

        // Photo
        if (!empty($_FILES['p_photo']['name'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attachment_id = media_handle_upload('p_photo', 0);
            if (!is_wp_error($attachment_id)) {
                update_user_meta($user_id, 'upload_photo', (int) $attachment_id);
            }
        }

        // Availability
        update_user_meta($user_id, 'months',  $text($P['months']));
        update_user_meta($user_id, 'years',   $text($P['years']));
        update_user_meta($user_id, 'reason',  $text($P['reason']));
        update_user_meta($user_id, 'league',  $text($P['league'] ?? ''));

        // Extras
        update_user_meta($user_id, 'fitness_stats', $text($P['fitness_stats'] ?? ''));
        $degree_arr = array_values(array_filter(array_map($text, (array) ($P['degree'] ?? []))));
        update_user_meta($user_id, 'degree', $degree_arr);

        // Flags
        update_user_meta($user_id, 'is_player', '1');
        update_user_meta($user_id, '_oval15_t_and_c', !empty($P['t_and_c']) ? 'yes' : '');
        update_user_meta($user_id, '_oval15_sole_rep', !empty($P['oval15_sole_rep']) ? 'yes' : '');
        update_user_meta($user_id, '_oval15_marketing_consent', !empty($P['marketing_consent']) ? 'yes' : '');
    }

    /** Order summary */
    private static function order_panel(\WC_Order $order) {
        $items = [];
        foreach ($order->get_items() as $item) $items[] = esc_html($item->get_name());
        $email = $order->get_billing_email();
        $date  = $order->get_date_created() ? $order->get_date_created()->date_i18n(get_option('date_format') . ' ' . get_option('time_format')) : '';
        ob_start(); ?>
        <section class="oval15-order-panel">
            <div class="oval15-order-head">
                <strong>Complete Registration</strong>
                <span>Order #<?php echo esc_html($order->get_order_number()); ?></span>
            </div>
            <div class="oval15-order-body">
                <div><b>Date:</b> <?php echo esc_html($date); ?></div>
                <div><b>Email:</b> <?php echo esc_html($email); ?></div>
                <div><b>Product(s):</b> <?php echo esc_html(implode(', ', $items)); ?></div>
            </div>
            <div class="oval15-order-steps">
                <ol>
                    <li>Complete all fields below (match your live registration).</li>
                    <li>Click <b>Register</b> to submit for approval.</li>
                    <li>We’ll email you once your profile is approved and visible to clubs.</li>
                </ol>
            </div>
        </section>
        <style>
            .oval15-order-panel{border:1px solid #e5e7eb;border-radius:8px;margin:8px 0 16px;background:#f9fafb}
            .oval15-order-head{display:flex;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #e5e7eb}
            .oval15-order-body{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;padding:10px 14px}
            .oval15-order-steps{padding:8px 14px 12px}
            @media (max-width:768px){.oval15-order-body{grid-template-columns:1fr}}
        </style>
        <?php
        return ob_get_clean();
    }

    /** Success / next steps */
    private static function success_panel(\WC_Order $order) {
        $account_url = wc_get_page_permalink('myaccount');
        ob_start(); ?>
        <section class="oval15-success-panel">
            <div class="oval15-success-head">
                <strong>Thanks! Your registration was submitted.</strong>
            </div>
            <div class="oval15-success-body">
                <p>Your profile is now <b>pending review</b>. We’ll email you once it’s approved and visible to clubs.</p>
                <ul>
                    <li>Check your details in <a href="<?php echo esc_url($account_url); ?>">My Account</a>.</li>
                    <li>Need a change? Contact support with your order number <b>#<?php echo esc_html($order->get_order_number()); ?></b>.</li>
                </ul>
            </div>
        </section>
        <style>
            .oval15-success-panel{border:1px solid #d1fae5;border-radius:8px;margin:8px 0 16px;background:#ecfdf5}
            .oval15-success-head{padding:10px 14px;border-bottom:1px solid #d1fae5;color:#065f46}
            .oval15-success-body{padding:12px 14px;color:#065f46}
            .oval15-success-body ul{margin:8px 0 0 18px;list-style:disc}
        </style>
        <?php
        return ob_get_clean();
    }

    /** Form (same as your last file; includes degree[] + fitness_stats) */
    private static function form_html($order_id, $key, $user_id, $posted = []) {
        $get = function($name, $meta_key = null) use ($posted, $user_id) {
            if (is_array($posted) && array_key_exists($name, $posted)) {
                return is_array($posted[$name]) ? $posted[$name] : (string) $posted[$name];
            }
            $mk = $meta_key ?: $name;
            return get_user_meta($user_id, $mk, true);
        };

        $positions = [
            'Prop (1)','Hooker','Prop (3)','Lock (4)','Lock (5)',
            'Flank (Openside)','Flank (Blindside)','No 8','Scrumhalf','Flyhalf',
            'Inside Centre (12)','Outside Centre (13)','Wing','Fullback','Utility Back'
        ];
        $countries = [
            'Angola','Argentina','Australia','Belgium','Brazil','Canada','Chile','China','Czech Republic',
            'Democratic Republic of Congo','England','European Union (EU)','Fiji','France','Georgia','Germany',
            'Ghana','Hong Kong','Hungary','Ireland','Italy','Japan','Kenya','Madagascar','Namibia','Netherlands',
            'New Zealand','Nigeria','Poland','Portugal','Qatar','Romania','Russia','Samoa','Scotland',
            'South Africa','Spain','Sweden','Tanzania','Thailand','Tonga','Trinidad and Tobago','Tunisia',
            'UAE','Uganda','Ukraine','United Kingdom','United States','Uruguay','Wales','Zambia','Zimbabwe'
        ];
        $levels_multi = ['Amateur','Academy','Semi-pro','Professional'];
        $lop_options  = ['Amateur','Semi-Pro','Professional'];
        $months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        $years  = ['2024','2025','2026','2027','2028'];
        $degrees = ['N/A','Diploma','Bachelors Degree','Honours','Masters'];

        $opt = function($options, $selected, $placeholder = null) {
            $html = '';
            if ($placeholder !== null) {
                $sel = ($selected==='' || $selected===null) ? ' selected' : '';
                $html .= '<option value=""'.$sel.'>'.esc_html($placeholder).'</option>';
            }
            foreach ($options as $o) {
                $sel = ((string)$selected === (string)$o) ? ' selected' : '';
                $html .= '<option value="'.esc_attr($o).'"'.$sel.'>'.esc_html($o).'</option>';
            }
            return $html;
        };
        $opt_multi = function($options, $selected_arr) {
            $selected_arr = is_array($selected_arr) ? $selected_arr : array_filter(array_map('trim', explode(',', (string) $selected_arr)));
            $chosen = array_fill_keys($selected_arr, true);
            $html = '';
            foreach ($options as $o) {
                $sel = isset($chosen[$o]) ? ' selected' : '';
                $html .= '<option value="'.esc_attr($o).'"'.$sel.'>'.esc_html($o).'</option>';
            }
            return $html;
        };

        $pre_levels     = $get('level');
        $pre_secondary  = $get('secondary_position') ?: $get('secondary-position');
        $pre_interested = $get('interested_country');
        $pre_passport   = $get('passport');
        $pre_degrees    = $get('degree');

        ob_start(); ?>
        <form method="post" class="woocommerce-form woocommerce-form-register register" enctype="multipart/form-data">
            <?php if (function_exists('wp_nonce_field')) wp_nonce_field('oval15_complete_reg', '_oval15_nonce'); ?>
            <input type="hidden" name="order" value="<?php echo esc_attr($order_id); ?>">
            <input type="hidden" name="key"   value="<?php echo esc_attr($key); ?>">

            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                <label for="reg_email">Email address&nbsp;<span class="required">*</span></label><br>
                <input type="email" class="woocommerce-Input woocommerce-Input--text input-text" name="email" id="reg_email" autocomplete="email" value="<?php echo esc_attr($get('email')); ?>">
            </p>
            <p>A link to set a new password will be sent to your email address.</p>

            <div class="clear">
                <h3>Personal Details</h3>

                <p class="form-row form-row-first"><label for="reg_billing_first_name">First name <span class="required">*</span></label></p>
                <p><input type="text" class="input-text" name="billing_first_name" id="reg_billing_first_name" value="<?php echo esc_attr($get('billing_first_name','first_name')); ?>" required></p>

                <p class="form-row form-row-last"><label for="reg_billing_last_name">Last name <span class="required">*</span></label></p>
                <p><input type="text" class="input-text" name="billing_last_name" id="reg_billing_last_name" value="<?php echo esc_attr($get('billing_last_name','last_name')); ?>" required></p>

                <p class="form-row form-row-last"><label for="gender">Gender <span class="required">*</span></label></p>
                <p>
                    <select class="input-text" name="gender" id="gender" required>
                        <?php echo $opt(['Male','Female'], $get('gender')); ?>
                    </select>
                </p>

                <p class="form-row form-row-last"><label for="lop">Level of player <span class="required">*</span></label></p>
                <p>
                    <select class="input-text" name="lop" id="lop" required>
                        <?php echo $opt($lop_options, $get('lop'), '-- Select level --'); ?>
                    </select>
                </p>

                <p class="form-row form-row-last">
                    <label for="reg_contact_number">Contact number <span class="required">*</span></label><br>
                    <input type="tel" class="input-text" name="contact_number" id="reg_contact_number" value="<?php echo esc_attr($get('contact_number')); ?>" autocomplete="tel">
                    <br><input type="hidden" value="<?php echo esc_attr($get('country_code')); ?>" name="country_code" id="country-code">
                </p>

                <p class="form-row form-row-first">
                    <label for="dob">Date Of Birth <span class="required">*</span></label><br>
                    <input type="date" class="input-text" name="dob" id="dob" value="<?php echo esc_attr($get('dob')); ?>" required>
                </p>

                <div class="form-group">
                    <label for="main-position">Main Position <span class="required">*</span></label><br>
                    <select name="main-position" id="main-position" required>
                        <?php echo $opt($positions, $get('main-position','position'), '-- Select main position --'); ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="secondary-position">Secondary Position(s)</label><br>
                    <select name="secondary-position[]" id="secondary-position" class="select2_box" multiple>
                        <?php echo $opt_multi($positions, $pre_secondary); ?>
                    </select><p></p>
                </div>

                <p class="form-row form-row-last">
                    <label for="nation">Nationality <span class="required">*</span></label><br>
                    <select class="select2_box" name="nation" id="nation" required>
                        <?php echo $opt($countries, $get('nation','nationality'), 'Select Country'); ?>
                    </select>
                </p>

                <p class="form-row form-row-first">
                    <label for="reg_passport">Passport <span class="required">*</span></label><br>
                    <select class="select2_box" name="passport[]" id="reg_passport" multiple>
                        <?php echo $opt_multi($countries, $pre_passport); ?>
                    </select>
                </p>

                <p class="form-row form-row-last">
                    <label for="current_location_country">Current Location (Country) <span class="required">*</span></label><br>
                    <select class="select2_box" name="current_location_country" id="current_location_country" required>
                        <?php echo $opt($countries, $get('current_location_country'), 'Select Country'); ?>
                    </select>
                </p>

                <div class="form-group">
                    <p><label for="weight">Weight (In KG) <span class="required">*</span></label></p>
                    <p><input type="number" class="form-control" id="weight" name="weight" value="<?php echo esc_attr($get('weight')); ?>" required></p>
                </div>

                <div class="form-group">
                    <p><label for="height">Height (In CM) <span class="required">*</span> </label></p>
                    <p><input type="number" class="form-control" id="height" name="height" min="150" max="1000" step="1" value="<?php echo esc_attr($get('height')); ?>" required></p>
                </div>

                <h4>Education</h4>
                <div class="form-group">
                    <label for="reg_degree">Academic degree(s)</label><br>
                    <select class="input-text select2_box" name="degree[]" id="reg_degree" multiple>
                        <?php echo $opt_multi($degrees, $pre_degrees); ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="reg_fitness_stats">Fitness stats</label><br>
                    <input type="text" class="input-text" name="fitness_stats" id="reg_fitness_stats" value="<?php echo esc_attr($get('fitness_stats')); ?>">
                </div>

                <h4>Current playing history</h4>
                <div class="form-group" style="display:flex;gap:10px;margin:0;">
                    <div>
                        <label for="club_1">Club Name <span class="required">*</span></label><br>
                        <input type="text" class="form-control" id="club_1" name="club_1" value="<?php echo esc_attr($get('club_1','club')); ?>" required><p></p>
                    </div>
                    <div>
                        <p><label for="tournament_1">League <span class="required">*</span></label></p>
                        <p><input type="text" class="form-control" id="tournament_1" name="tournament_1" value="<?php echo esc_attr($get('tournament_1','tournament')); ?>" required></p>
                    </div>
                    <div>
                        <p><label for="period_1">Period <span class="required">*</span></label></p>
                        <p><input type="text" class="form-control" id="period_1" name="period_1" value="<?php echo esc_attr($get('period_1','period')); ?>" required></p>
                        <p><small>(add in 2024-2025 below)</small></p>
                    </div>
                    <p></p>
                </div>

                <div class="form-group" style="display:flex;gap:10px;">
                    <div>
                        <label for="club_2">Club Name</label><br>
                        <input type="text" class="form-control" id="club_2" name="club_2" value="<?php echo esc_attr($get('club_2')); ?>">
                    </div>
                    <div>
                        <label for="tournament_2">League</label><br>
                        <input type="text" class="form-control" id="tournament_2" name="tournament_2" value="<?php echo esc_attr($get('tournament_2')); ?>">
                    </div>
                    <div>
                        <label for="period_2">Period</label><br>
                        <input type="text" class="form-control" id="period_2" name="period_2" value="<?php echo esc_attr($get('period_2')); ?>">
                    </div>
                    <p></p>
                </div>

                <div class="form-group" style="display:flex;gap:10px;">
                    <div>
                        <label for="club_3">Club Name</label><br>
                        <input type="text" class="form-control" id="club_3" name="club_3" value="<?php echo esc_attr($get('club_3')); ?>">
                    </div>
                    <div>
                        <label for="tournament_3">League</label><br>
                        <input type="text" class="form-control" id="tournament_3" name="tournament_3" value="<?php echo esc_attr($get('tournament_3')); ?>">
                    </div>
                    <div>
                        <label for="period_3">Period</label><br>
                        <input type="text" class="form-control" id="period_3" name="period_3" value="<?php echo esc_attr($get('period_3')); ?>">
                    </div>
                    <p></p>
                </div>

                <h4 class="mb-0 mt-4">Player Profile</h4>
                <p><small>Tell us about your strengths as a player, notable achievements, any representative teams, etc.</small><br>
                    <a class="custom__link" target="_blank" href="/news/how-to-sell-yourself-in-your-player-profile/">How To Sell Yourself In Your Player Profile</a>
                </p>

                <div class="form-group">
                    <label for="profile">Profile <span class="required">*</span></label><br>
                    <textarea name="p_profile" id="profile" class="p-profile-count" cols="50" rows="10" aria-required="true"><?php echo esc_textarea($get('p_profile','profile')); ?></textarea>
                </div>
                <div id="word-count">Word Count: 0 / 200</div>

                <h4>Video footage</h4>
                <p class="form-row form-row-last">
                    <label for="reg_v_link">Video Link (YouTube or Vimeo Link Only) <span class="required">*</span></label><br>
                    <input type="text" class="input-text" name="v_link" id="reg_v_link" value="<?php echo esc_attr($get('v_link','link')); ?>" required>
                </p>

                <h4>Upload Photo (Square Shaped)</h4>
                <p class="form-row form-row-last">
                    <label for="reg_p_photo">Profile Pic <?php echo (int) get_user_meta($user_id,'upload_photo', true) > 0 ? '' : '<span class="required">*</span>'; ?></label><br>
                    <input type="file" class="input-text" name="p_photo" id="reg_p_photo" accept="image/png, image/jpeg" <?php echo (int) get_user_meta($user_id,'upload_photo', true) > 0 ? '' : 'required'; ?>>
                </p>

                <p class="form-row form-row-last">
                    <label for="level">Level looking for <span class="required">*</span></label><br>
                    <select class="input-text select2_box" name="level[]" id="level" multiple>
                        <?php echo $opt_multi($levels_multi, $pre_levels); ?>
                    </select>
                </p>

                <p class="form-row form-row-last">
                    <label for="interested_country">Countries interested in playing in? <span class="required">*</span></label><br>
                    <select class="input-text select2_box" name="interested_country[]" id="interested_country" multiple>
                        <?php echo $opt_multi($countries, $pre_interested); ?>
                    </select>
                </p>

                <h4>Available From</h4>
                <p class="form-row form-row-last">
                    <label for="months">Month <span class="required">*</span></label><br>
                    <select class="input-text" name="months" id="months" required>
                        <?php echo $opt($months, $get('months')); ?>
                    </select>
                </p>

                <p class="form-row form-row-last">
                    <label for="years">Year <span class="required">*</span></label><br>
                    <select id="years" name="years" required>
                        <?php echo $opt($years, $get('years')); ?>
                    </select>
                </p>

                <p class="form-row form-row-last">
                    <label for="reason">If available, please write “Available Now”. If not available now, please provide reason (Under Contract, Committed for Season, Finishing School etc) <span class="required">*</span></label><br>
                    <input type="text" id="reason" name="reason" value="<?php echo esc_attr($get('reason')); ?>" required>
                </p>

                <p><input type="hidden" hidden name="is_player" id="is_player" value="1"></p>

                <p class="form-row form-row-last terms_conditions_box">
                    <input type="checkbox" class="input-text" name="t_and_c" id="t_and_c" <?php checked(!empty($posted['t_and_c'])); ?> required><br>
                    <label for="t_and_c">Agree Terms and Conditions <span class="required">*</span></label>
                    <small> – <a href="/terms-and-conditions" target="_blank" rel="noopener">Read Terms</a></small>
                </p>

                <p class="form-row">
                    <input type="checkbox" name="oval15_sole_rep" id="oval15_sole_rep" value="1" <?php checked(get_user_meta($user_id,'_oval15_sole_rep', true) === 'yes' || !empty($posted['oval15_sole_rep'])); ?>>
                    <label for="oval15_sole_rep">I confirm Oval15 as my sole representative.</label>
                </p>
                <p class="form-row">
                    <input type="checkbox" name="marketing_consent" id="marketing_consent" value="1" <?php checked(get_user_meta($user_id,'_oval15_marketing_consent', true) === 'yes' || !empty($posted['marketing_consent'])); ?>>
                    <label for="marketing_consent">I agree to receive marketing emails.</label>
                </p>

                <p class="form-row">
                    <button type="submit" class="woocommerce-Button woocommerce-button button woocommerce-form-register__submit" name="oval15_complete_submit" value="1">Register</button>
                </p>
            </div>
        </form>

        <script>
        (function($){
            if (!$) return;

            $(function(){
                if ($.fn.select2) {
                    $('.select2_box').each(function(){ try { $(this).select2({ width: '100%' }); } catch(e){} });
                }
                var $ta = $('#profile'), $wc = $('#word-count');
                if ($ta.length && $wc.length) {
                    var updateCount = function(){
                        var text = $ta.val().trim();
                        var words = text ? text.split(/\s+/).length : 0;
                        $wc.text('Word Count: ' + words + ' / 200');
                    };
                    $ta.on('input', updateCount); updateCount();
                }
            });

            $(function () {
              var $ta = $('#profile');
              if ($ta.length) {
                var maybeDropRequired = function() {
                  if ($ta.is(':hidden')) $ta.removeAttr('required');
                };
                maybeDropRequired();
                var obs = new MutationObserver(maybeDropRequired);
                obs.observe($ta[0], { attributes: true, attributeFilter: ['style', 'class'] });
              }
            });

            $(function(){
                var $tel = $('#reg_contact_number'), $cc = $('#country-code');
                if (!$tel.length) return;
                if (window.intlTelInput) {
                    try {
                        var iti = window.intlTelInput($tel[0], { separateDialCode: true, utilsScript: '' });
                        var sync = function(){
                            try {
                                var data = iti.getSelectedCountryData();
                                if ($cc.length && data && data.dialCode) $cc.val('+' + data.dialCode);
                            } catch(e){}
                        };
                        $tel.on('countrychange keyup change', sync);
                        sync();
                    } catch(e){}
                }
            });
        })(window.jQuery);
        </script>
        <?php
        return ob_get_clean();
    }

    /** Transient state for PRG */
    private static function state_key($order_id, $key, $user_id) {
        $salt = wp_salt('auth');
        return 'oval15_form_state_' . md5($order_id . '|' . $key . '|' . $user_id . '|' . $salt);
    }
    private static function push_state($order_id, $key, $user_id, array $data) {
        set_transient(self::state_key($order_id,$key,$user_id), $data, 5 * MINUTE_IN_SECONDS);
    }
    private static function pull_state($order_id, $key, $user_id) {
        $k = self::state_key($order_id,$key,$user_id);
        $v = get_transient($k);
        if ($v !== false) delete_transient($k);
        return $v;
    }

    /** Redirect helpers */
    private static function build_base_url($order_id, $key) {
        $base = wp_get_referer();
        if (!$base) {
            $req  = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
            $base = home_url($req);
        }
        $base = remove_query_arg(['submitted','errors'], $base);
        $base = add_query_arg(['order' => $order_id, 'key' => $key], $base);
        return $base;
    }
    private static function redirect_with_errors($order_id, $key, array $posted, array $errors) {
        $user_id = 0;
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) $user_id = (int) $order->get_user_id();
        }
        self::push_state($order_id, $key, $user_id, ['errors' => array_values($errors), 'posted' => $posted]);
        $url = add_query_arg(['errors' => '1'], self::build_base_url($order_id, $key));
        self::safe_redirect($url);
    }
    private static function safe_redirect($url) {
        nocache_headers();
        wp_safe_redirect($url);
        exit;
    }

    /** Notices */
    private static function error_list(array $errors) {
        $html = '<ul class="woocommerce-error">';
        foreach ($errors as $e) $html .= '<li>'.esc_html($e).'</li>';
        $html .= '</ul>';
        return $html;
    }
    private static function error_box($msg) {
        return '<div class="woocommerce-error">'.esc_html($msg).'</div>';
    }

    private static function count_words($text) {
        $text = wp_strip_all_tags((string) $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text === '') return 0;
        return count(explode(' ', $text));
    }
    private static function limit_words($text, $limit = 200) {
        $text = wp_strip_all_tags((string) $text);
        $words = preg_split('/\s+/', trim($text));
        if (count($words) <= $limit) return $text;
        return implode(' ', array_slice($words, 0, $limit));
    }
}
<?php
namespace Oval15\Core\Registration;

use Oval15\Core\Admin\Settings;
use Oval15\Core\Media\Video;

if (!defined('ABSPATH')) exit;

class Endpoint
{
    public static function init() {
        add_shortcode('oval15_complete_registration', [__CLASS__, 'render_shortcode']);
        add_action('template_redirect', [__CLASS__, 'maybe_handle_post']);
    }

    /** Simple static caches so we don’t rebuild arrays repeatedly */
    private static $COUNTRIES = null;
    private static $POSITIONS = null;

    private static function countries() {
        if (self::$COUNTRIES !== null) return self::$COUNTRIES;
        // Country list aligned to live (you can extend if needed)
        self::$COUNTRIES = [
            "Angola","Argentina","Australia","Belgium","Brazil","Canada","Chile","China",
            "Czech Republic","Democratic Republic of Congo","England","European Union (EU)","Fiji",
            "France","Georgia","Germany","Ghana","Hong Kong","Hungary","Ireland","Italy",
            "Japan","Kenya","Madagascar","Namibia","Netherlands","New Zealand","Nigeria","Poland",
            "Portugal","Qatar","Romania","Russia","Samoa","Scotland","South Africa","Spain",
            "Sweden","Tanzania","Thailand","Tonga","Trinidad and Tobago","Tunisia","UAE","Uganda",
            "Ukraine","United Kingdom","United States","Uruguay","Wales","Zambia","Zimbabwe"
        ];
        return self::$COUNTRIES;
    }

    private static function positions() {
        if (self::$POSITIONS !== null) return self::$POSITIONS;
        self::$POSITIONS = [
            'Prop (1)',
            'Hooker',
            'Prop (3)',
            'Lock (4)',
            'Lock (5)',
            'Flank (Openside)',
            'Flank (Blindside)',
            'No 8',
            'Scrumhalf',
            'Flyhalf',
            'Inside Centre (12)',
            'Outside Centre (13)',
            'Wing',
            'Fullback',
            'Utility Back',
        ];
        return self::$POSITIONS;
    }
    public static function render_shortcode($atts = []) {
        if (!function_exists('wc_get_order')) {
            return '<div class="woocommerce-error">WooCommerce is not available.</div>';
        }

        // Accept order & key (or order-received)
        $order_id  = isset($_GET['order']) ? absint($_GET['order']) : 0;
        if (!$order_id && isset($_GET['order-received'])) {
            $order_id = absint($_GET['order-received']);
        }
        $order_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';

        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order || ($order_key && $order->get_order_key() !== $order_key)) {
            return '<div class="woocommerce-error">Invalid or missing order.</div>';
        }

        // Create/attach user if needed (same logic as before)
        $billing_email = trim((string)$order->get_billing_email());
        if (!is_email($billing_email)) {
            return '<div class="woocommerce-error">This order has no billing email; please contact support.</div>';
        }

        $user_id = (int) $order->get_user_id();
        if ($user_id <= 0) {
            $username = self::unique_username_from_email($billing_email);
            $password = wp_generate_password(18, true, true);
            $user_id  = wp_create_user($username, $password, $billing_email);
            if (is_wp_error($user_id)) {
                return '<div class="woocommerce-error">Could not create your account. Please contact support.</div>';
            }
            update_user_meta($user_id, '_oval15_approved', 'no');
            $order->set_customer_id($user_id);
            $order->save();
        }

        // Success notice
        if (isset($_GET['oval15_complete']) && $_GET['oval15_complete'] == '1') {
            return '<div class="woocommerce-message">Thanks! Your registration has been submitted.</div>';
        }

        // Prefill values
        $pref = function($k, $def = '') use ($user_id) {
            $v = get_user_meta($user_id, $k, true);
            return ($v !== '' && $v !== null) ? $v : $def;
        };
        $countries = self::countries();
        $positions = self::positions();

        // existing values
        $email        = $billing_email;
        $first_name   = get_user_meta($user_id, 'first_name', true);
        $last_name    = get_user_meta($user_id, 'last_name', true);
        $gender       = $pref('gender');
        $lop          = $pref('level'); // "level of player" (lop) maps to meta 'level'
        $contact      = $pref('contact_number');
        $country_code = $pref('country_code');
        $dob          = $pref('dob');
        $main_pos     = $pref('main-position');
        $secondaryCSV = $pref('secondary-position'); // CSV in meta
        $secondaryArr = array_filter(array_map('trim', is_array($secondaryCSV) ? $secondaryCSV : explode(',', (string)$secondaryCSV)));
        $nation       = $pref('nationality'); // live field "nation"
        $passportsCSV = $pref('passport');
        $passportsArr = array_filter(array_map('trim', explode(',', (string)$passportsCSV)));
        $current_loc  = $pref('current_location_country');

        $weight       = $pref('weight');
        $height       = $pref('height');

        $club_1       = $pref('club');
        $tournament_1 = $pref('tournament_1');
        $period_1     = $pref('period');
        $club_2       = $pref('club_2');
        $tournament_2 = $pref('tournament_2');
        $period_2     = $pref('period_2');
        $club_3       = $pref('club_3');
        $tournament_3 = $pref('tournament_3');
        $period_3     = $pref('period_3');

        $profile      = $pref('profile'); // rich text; we’ll save from p_profile too
        $link         = $pref('link');    // primary video link
        $v_links_csv  = $pref('v_links'); // extra links CSV
        $upload_photo = (int)$pref('upload_photo'); // attachment id

        // Settings for media restrictions
        $opt          = get_option(Settings::OPTION, []);
        $allow_upload = is_array($opt) ? !empty($opt['video_uploads']) : false;
        $max_mb       = is_array($opt) ? (int)($opt['video_max_mb'] ?? 100) : 100;

        ob_start();
        ?>

        <!-- Enqueue CDN assets (kept local to shortcode to avoid theme deps) -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/css/intlTelInput.css">
        <style>
            .select2-container { width: 100% !important; }
            .iti { width: 100%; }
            .custom__link { text-decoration: underline; }
            .form-group { margin-bottom: 16px; }
            .required { color: #e11d48; }
        </style>

<form method="post" class="oval15-complete-registration woocommerce-form woocommerce-form-register register" enctype="multipart/form-data">
    <?php wp_nonce_field('oval15_complete_reg_'.$user_id, '_wpnonce'); ?>
    <input type="hidden" name="oval15_action" value="complete_registration">
    <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>">
    <input type="hidden" name="order_key" value="<?php echo esc_attr($order->get_order_key()); ?>">


            <!-- Email -->
            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                <label for="reg_email">Email address&nbsp;<span class="required">*</span></label><br>
                <input type="email" class="woocommerce-Input woocommerce-Input--text input-text" name="email" id="reg_email" autocomplete="email" value="<?php echo esc_attr($email); ?>">
            </p>
            <p>A link to set a new password will be sent to your email address.</p>

            <!-- Newsletter (opt-in; not saved here – your MC plugin reads it) -->
            <p class="form-row form-row-wide mailchimp-newsletter">
                <label for="mailchimp_woocommerce_newsletter" class="woocommerce-form__label woocommerce-form__label-for-checkbox inline">
                    <input class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" id="mailchimp_woocommerce_newsletter" type="checkbox" name="mailchimp_woocommerce_newsletter" value="1" checked>
                    <span>Subscribe to our newsletter</span>
                </label>
            </p>

            <div class="clear">
            <h3>Personal Details</h3>

            <!-- First / Last -->
            <p class="form-row form-row-first">
                <label for="reg_billing_first_name">First name <span class="required">*</span></label><br>
                <input type="text" class="input-text" name="billing_first_name" id="reg_billing_first_name" value="<?php echo esc_attr($first_name); ?>">
            </p>
            <p class="form-row form-row-last">
                <label for="reg_billing_last_name">Last name <span class="required">*</span></label><br>
                <input type="text" class="input-text" name="billing_last_name" id="reg_billing_last_name" value="<?php echo esc_attr($last_name); ?>">
            </p>

            <!-- Gender -->
            <p class="form-row form-row-last">
                <label for="gender">Gender <span class="required">*</span></label><br>
                <select class="input-text" name="gender" id="gender" required>
                    <option value="Male"   <?php selected($gender, 'Male'); ?>>Male</option>
                    <option value="Female" <?php selected($gender, 'Female'); ?>>Female</option>
                </select>
            </p>

            <!-- Level of player -->
            <p class="form-row form-row-last">
                <label for="lop">Level of player <span class="required">*</span></label><br>
                <select class="input-text" name="lop" id="lop" required>
                    <option value="Amateur"      <?php selected($lop, 'Amateur'); ?>>Amateur</option>
                    <option value="Semi-Pro"     <?php selected($lop, 'Semi-Pro'); ?>>Semi-Pro</option>
                    <option value="Professional" <?php selected($lop, 'Professional'); ?>>Professional</option>
                </select>
            </p>

            <!-- Contact (intl-tel-input) + hidden country code -->
            <p class="form-row form-row-last">
                <label for="reg_contact_number">Contact number <span class="required">*</span></label><br>
                <input type="tel" class="input-text" name="contact_number" id="reg_contact_number" value="<?php echo esc_attr($contact); ?>" autocomplete="off" style="padding-left: 73px;">
                <br><input type="hidden" value="<?php echo esc_attr($country_code); ?>" name="country_code" id="country-code">
            </p>

            <!-- DOB -->
            <p class="form-row form-row-first">
                <label for="dob">Date Of Birth <span class="required">*</span></label><br>
                <input type="date" class="input-text" name="dob" id="dob" value="<?php echo esc_attr($dob); ?>">
            </p>

            <!-- Main & Secondary positions -->
            <div class="form-group">
                <label for="main-position">Main Position <span class="required">*</span></label><br>
                <select name="main-position" id="main-position" required>
                    <?php foreach ($positions as $p) : ?>
                        <option value="<?php echo esc_attr($p); ?>" <?php selected($main_pos, $p); ?>><?php echo esc_html($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="secondary-position">Secondary Position(s)</label><br>
                <select name="secondary-position[]" id="secondary-position" class="select2_box" multiple data-reorder="1">
                    <?php foreach ($positions as $p) : ?>
                        <option value="<?php echo esc_attr($p); ?>" <?php selected(in_array($p, $secondaryArr, true), true); ?>><?php echo esc_html($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Nationality -->
            <p class="form-row form-row-last">
                <label for="nation">Nationality <span class="required">*</span></label><br>
                <select class="select2_box" name="nation" id="nation">
                    <option value="">Select Country</option>
                    <?php foreach ($countries as $c): ?>
                        <option value="<?php echo esc_attr($c); ?>" <?php selected($nation, $c); ?>><?php echo esc_html($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>

            <!-- Passports -->
            <p class="form-row form-row-first">
                <label for="reg_passport">Passport <span class="required">*</span></label><br>
                <select class="select2_box" name="passport[]" id="reg_passport" multiple required>
                    <?php foreach ($countries as $c): ?>
                        <option value="<?php echo esc_attr($c); ?>" <?php selected(in_array($c, $passportsArr, true), true); ?>><?php echo esc_html($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>

            <!-- Current location -->
            <p class="form-row form-row-last">
                <label for="current_location_country">Current Location (Country) <span class="required">*</span></label><br>
                <select class="select2_box" name="current_location_country" id="current_location_country">
                    <option value="">Select Country</option>
                    <?php foreach ($countries as $c): ?>
                        <option value="<?php echo esc_attr($c); ?>" <?php selected($current_loc, $c); ?>><?php echo esc_html($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>

            <!-- Weight / Height -->
            <div class="form-group">
                <label for="weight">Weight (In KG) <span class="required">*</span></label><br>
                <input type="number" class="form-control" id="weight" name="weight" required value="<?php echo esc_attr($weight); ?>">
            </div>
            <div class="form-group">
                <label for="height">Height (In CM) <span class="required">*</span></label><br>
                <input type="number" class="form-control" id="height" name="height" min="150" max="1000" required step="1" value="<?php echo esc_attr($height); ?>">
            </div>

            <!-- Playing history -->
            <h4>Current playing history</h4>
            <div class="form-group" style="display:flex;gap:10px;margin:0;">
                <div>
                    <label for="club_1">Club Name <span class="required">*</span></label><br>
                    <input type="text" class="form-control" id="club_1" name="club_1" required value="<?php echo esc_attr($club_1); ?>">
                </div>
                <div>
                    <label for="tournament_1">League <span class="required">*</span></label><br>
                    <input type="text" class="form-control" id="tournament_1" name="tournament_1" required value="<?php echo esc_attr($tournament_1); ?>">
                </div>
                <div>
                    <label for="period_1">Period <span class="required">*</span></label><br>
                    <input type="text" class="form-control" id="period_1" name="period_1" required value="<?php echo esc_attr($period_1); ?>">
                    <small>(add in 2024-2025 below)</small>
                </div>
            </div>

            <div class="form-group" style="display:flex;gap:10px;">
                <div>
                    <label for="club_2">Club Name</label><br>
                    <input type="text" class="form-control" id="club_2" name="club_2" value="<?php echo esc_attr($club_2); ?>">
                </div>
                <div>
                    <label for="tournament_2">League</label><br>
                    <input type="text" class="form-control" id="tournament_2" name="tournament_2" value="<?php echo esc_attr($tournament_2); ?>">
                </div>
                <div>
                    <label for="period_2">Period</label><br>
                    <input type="text" class="form-control" id="period_2" name="period_2" value="<?php echo esc_attr($period_2); ?>">
                </div>
            </div>

            <div class="form-group" style="display:flex;gap:10px;">
                <div>
                    <label for="club_3">Club Name</label><br>
                    <input type="text" class="form-control" id="club_3" name="club_3" value="<?php echo esc_attr($club_3); ?>">
                </div>
                <div>
                    <label for="tournament_3">League</label><br>
                    <input type="text" class="form-control" id="tournament_3" name="tournament_3" value="<?php echo esc_attr($tournament_3); ?>">
                </div>
                <div>
                    <label for="period_3">Period</label><br>
                    <input type="text" class="form-control" id="period_3" name="period_3" value="<?php echo esc_attr($period_3); ?>">
                </div>
            </div>

            <!-- Player Profile (CKEditor target = p_profile) -->
            <h4 class="mb-0 mt-4">Player Profile</h4>
            <p><small>Tell us about your strengths as a player, notable achievements, any representative teams, etc.</small><br>
            <a class="custom__link" target="_blank" href="/news/how-to-sell-yourself-in-your-player-profile/">How To Sell Yourself In Your Player Profile</a></p>

            <div class="form-group">
                <label for="profile">Profile <span class="required">*</span></label><br>
                <textarea name="p_profile" id="profile" class="p-profile-count" cols="50" rows="10" required><?php echo esc_textarea($profile); ?></textarea>
            </div>

            <!-- Media (Video & Photo) -->
            <h4>Media</h4>
            <div class="form-group">
                <label for="link">Primary Video Link (YouTube/Vimeo etc.) <span class="required">*</span></label><br>
                <input type="url" class="form-control" id="link" name="link" required value="<?php echo esc_attr($link); ?>">
            </div>
            <div class="form-group">
                <label for="v_links">Additional Video Links (comma separated)</label><br>
                <input type="text" class="form-control" id="v_links" name="v_links" value="<?php echo esc_attr($v_links_csv); ?>">
            </div>
            <?php if ($allow_upload): ?>
                <div class="form-group">
                    <label for="v_upload">Upload MP4 (max <?php echo (int)$max_mb; ?>MB)</label><br>
                    <input type="file" name="v_upload" id="v_upload" accept="video/mp4,video/webm,video/quicktime">
                </div>
            <?php endif; ?>
            <div class="form-group" id="user-thumnail">
                <h3>Upload photo (required)</h3>
                <?php if ($upload_photo) {
                    echo wp_get_attachment_image($upload_photo, [300,300], false, ['class' => 'img-responsive player-profile', 'style' => 'max-width:180px;border-radius:8px;margin-bottom:8px;']);
                } ?>
                <input type="file" class="form-control" name="p_photo" accept="image/*">
                <span class="error color-danger"></span>
            </div>

            <p class="form-row">
                <button type="submit" class="button button-primary">Submit Registration</button>
            </p>
        </form>

        <!-- JS assets -->
        <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/intlTelInput.min.js"></script>

        <script>
        (function($){
            // Select2
            $('.select2_box').select2();

            // CKEditor (profile)
            if (document.getElementById('profile')) {
                ClassicEditor.create(document.getElementById('profile'))
                .catch(function(e){console.warn('CKEditor error', e);});
            }

            // intl-tel-input
            var telInput = document.querySelector('#reg_contact_number');
            if (telInput && window.intlTelInput) {
                var iti = window.intlTelInput(telInput, {
                    initialCountry: 'auto',
                    separateDialCode: true,
                    utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/utils.js'
                });
                function syncDialCode(){
                    var c = iti.getSelectedCountryData();
                    $('#country-code').val(c ? ('+' + (c.dialCode||'')) : '');
                }
                telInput.addEventListener('countrychange', syncDialCode);
                telInput.addEventListener('blur', syncDialCode);
                syncDialCode();
            }
        })(jQuery);
        </script>
        <style>
.oval15-loading-overlay {
    display: none;
    position: fixed;
    top:0;left:0;right:0;bottom:0;
    background: rgba(255,255,255,0.85);
    z-index: 99999;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: #333;
}
.oval15-loading-overlay .spinner {
    border: 4px solid #e5e7eb;
    border-top: 4px solid #2563eb;
    border-radius: 50%;
    width: 48px;
    height: 48px;
    animation: spin 1s linear infinite;
    margin-right:12px;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<div class="oval15-loading-overlay" id="oval15-loading">
    <div class="spinner"></div>
    <div>Submitting your registration… please wait</div>
</div>

<script>
(function($){
    $('.oval15-complete-registration').on('submit', function(){
        var $btn = $(this).find('button[type=submit]');
        $btn.prop('disabled', true).text('Submitting…');
        $('#oval15-loading').fadeIn(200);
    });
})(jQuery);
</script>



        <?php
        return ob_get_clean();
    }
    public static function maybe_handle_post() {
        if (empty($_POST['oval15_action']) || $_POST['oval15_action'] !== 'complete_registration') return;

        $order_id  = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $order_key = isset($_POST['order_key']) ? sanitize_text_field($_POST['order_key']) : '';
        if (!function_exists('wc_get_order') || !$order_id) return;

        $order = wc_get_order($order_id);
        if (!$order) return;
        if ($order_key && $order->get_order_key() !== $order_key) return;

        $user_id = (int)$order->get_user_id();
        if ($user_id <= 0) return;

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'oval15_complete_reg_'.$user_id)) return;

        // Collect inputs (match live names)
        $email       = sanitize_email($_POST['email'] ?? '');
        $first_name  = sanitize_text_field($_POST['billing_first_name'] ?? '');
        $last_name   = sanitize_text_field($_POST['billing_last_name'] ?? '');
        $gender      = sanitize_text_field($_POST['gender'] ?? '');
        $lop         = sanitize_text_field($_POST['lop'] ?? ''); // maps to meta 'level'

        $contact     = sanitize_text_field($_POST['contact_number'] ?? '');
        $countryCode = sanitize_text_field($_POST['country_code'] ?? '');
        $dob         = sanitize_text_field($_POST['dob'] ?? '');

        $main_pos    = sanitize_text_field($_POST['main-position'] ?? '');
        $sec_pos_in  = (array)($_POST['secondary-position'] ?? []);
        $sec_pos     = array_filter(array_map('sanitize_text_field', $sec_pos_in));

        $nation      = sanitize_text_field($_POST['nation'] ?? '');
        $passports   = array_filter(array_map('sanitize_text_field', (array)($_POST['passport'] ?? [])));
        $current_loc = sanitize_text_field($_POST['current_location_country'] ?? '');

        $weight      = sanitize_text_field($_POST['weight'] ?? '');
        $height      = sanitize_text_field($_POST['height'] ?? '');

        $club_1       = sanitize_text_field($_POST['club_1'] ?? '');
        $tournament_1 = sanitize_text_field($_POST['tournament_1'] ?? '');
        $period_1     = sanitize_text_field($_POST['period_1'] ?? '');
        $club_2       = sanitize_text_field($_POST['club_2'] ?? '');
        $tournament_2 = sanitize_text_field($_POST['tournament_2'] ?? '');
        $period_2     = sanitize_text_field($_POST['period_2'] ?? '');
        $club_3       = sanitize_text_field($_POST['club_3'] ?? '');
        $tournament_3 = sanitize_text_field($_POST['tournament_3'] ?? '');
        $period_3     = sanitize_text_field($_POST['period_3'] ?? '');

        // CKEditor field
        $p_profile   = wp_kses_post($_POST['p_profile'] ?? '');

        // Media
        $link        = trim((string)($_POST['link'] ?? ''));
        $v_links_csv = (string)($_POST['v_links'] ?? '');

        // Validation (mirror live expectations)
        $errors = [];
        if (!is_email($email)) $errors[] = 'A valid email address is required.';
        if ($first_name === '' || $last_name === '') $errors[] = 'First & last name are required.';
        if ($gender === '') $errors[] = 'Gender is required.';
        if ($lop === '') $errors[] = 'Level of player is required.';
        if ($contact === '') $errors[] = 'Contact number is required.';
        if ($dob === '') $errors[] = 'Date of birth is required.';
        if ($main_pos === '') $errors[] = 'Main position is required.';
        if ($nation === '') $errors[] = 'Nationality is required.';
        if (empty($passports)) $errors[] = 'Passport is required.';
        if ($current_loc === '') $errors[] = 'Current location (country) is required.';
        if ($weight === '') $errors[] = 'Weight is required.';
        if ($height === '') $errors[] = 'Height is required.';
        if ($club_1 === '' || $tournament_1 === '' || $period_1 === '') $errors[] = 'Club, League, and Period (row 1) are required.';
        if ($p_profile === '') $errors[] = 'Profile is required.';
        if ($link === '' || !filter_var($link, FILTER_VALIDATE_URL)) $errors[] = 'Valid primary video link is required.';

        // Photo required (if none set yet)
        $existing_photo = (int)get_user_meta($user_id, 'upload_photo', true);
        $photo_id = $existing_photo;
        if (!empty($_FILES['p_photo']['name'])) {
            $photo_id = self::handle_image_upload($_FILES['p_photo'], ['jpg','jpeg','png','gif','webp']);
            if (is_wp_error($photo_id)) $errors[] = $photo_id->get_error_message();
        } elseif (!$existing_photo) {
            $errors[] = 'Profile photo is required.';
        }

        if (!empty($errors)) {
            foreach ($errors as $e) { if (function_exists('wc_add_notice')) wc_add_notice($e, 'error'); }
            wp_safe_redirect(add_query_arg([
                'order' => $order->get_id(),
                'key'   => $order->get_order_key(),
            ], get_permalink()));
            exit;
        }

        // Save account basics
        if ($email && $email !== wp_get_current_user()->user_email) {
            wp_update_user(['ID' => $user_id, 'user_email' => $email]); // ignore error; WC will show if any
        }
        if ($first_name !== get_user_meta($user_id, 'first_name', true)) update_user_meta($user_id, 'first_name', $first_name);
        if ($last_name  !== get_user_meta($user_id, 'last_name',  true)) update_user_meta($user_id, 'last_name',  $last_name);

        // Save scalar metas (legacy keys used by live templates)
        update_user_meta($user_id, 'gender', $gender);
        update_user_meta($user_id, 'level',  $lop); // lop -> level
        update_user_meta($user_id, 'contact_number', $contact);
        update_user_meta($user_id, 'country_code',   $countryCode);
        update_user_meta($user_id, 'dob', $dob);

        update_user_meta($user_id, 'main-position', $main_pos);
        update_user_meta($user_id, 'secondary-position', implode(',', $sec_pos));

        update_user_meta($user_id, 'nationality', $nation);
        update_user_meta($user_id, 'passport', implode(',', $passports));
        update_user_meta($user_id, 'current_location_country', $current_loc);

        update_user_meta($user_id, 'weight', $weight);
        update_user_meta($user_id, 'height', $height);

        update_user_meta($user_id, 'club',     $club_1);
        update_user_meta($user_id, 'tournament_1', $tournament_1);
        update_user_meta($user_id, 'period',   $period_1);
        update_user_meta($user_id, 'club_2',   $club_2);
        update_user_meta($user_id, 'tournament_2', $tournament_2);
        update_user_meta($user_id, 'period_2', $period_2);
        update_user_meta($user_id, 'club_3',   $club_3);
        update_user_meta($user_id, 'tournament_3', $tournament_3);
        update_user_meta($user_id, 'period_3', $period_3);

        // Player profile: keep both keys (legacy 'profile' + form name 'p_profile')
        update_user_meta($user_id, 'p_profile', $p_profile);
        update_user_meta($user_id, 'profile',   $p_profile);

        // Video links (validate host if setting present)
        $opt   = get_option(Settings::OPTION, []);
        $hosts = [];
        if (is_array($opt) && !empty($opt['video_hosts'])) {
            $hosts = array_filter(array_map('trim', explode(',', $opt['video_hosts'])));
        }

        if ($hosts && !Video::host_allowed($link, $hosts)) {
            if (function_exists('wc_add_notice')) wc_add_notice('Primary video link host is not in the allowed list.', 'notice');
        }
        update_user_meta($user_id, 'link', esc_url_raw($link));

        $links_in = array_filter(array_map('trim', explode(',', $v_links_csv)));
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

        // Optional direct video upload
        $allow_upload = is_array($opt) ? !empty($opt['video_uploads']) : false;
        if ($allow_upload && !empty($_FILES['v_upload']['name'])) {
            $max_mb    = is_array($opt) ? (int)($opt['video_max_mb'] ?? 100) : 100;
            $attach_id = Video::handle_upload($_FILES['v_upload'], $max_mb);
            if (!is_wp_error($attach_id)) {
                update_user_meta($user_id, 'v_upload_id', (int)$attach_id);
            } else {
                if (function_exists('wc_add_notice')) wc_add_notice($attach_id->get_error_message(), 'error');
            }
        }

        // Photo (required above)
        if ($photo_id) {
            update_user_meta($user_id, 'upload_photo', (int)$photo_id);
        }

        // Mark completion timestamp
        update_user_meta($user_id, '_oval15_reg_completed_at', current_time('mysql'));

        // Redirect success
        $redirect = add_query_arg([
            'order'           => $order->get_id(),
            'key'             => $order->get_order_key(),
            'oval15_complete' => 1,
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

        $overrides = ['test_form' => false];
        $move = wp_handle_upload($file, $overrides);
        if (isset($move['error'])) {
            return new \WP_Error('oval15_upload_error', $move['error']);
        }

        $url  = $move['url'];
        $type = $move['type'];
        $path = $move['file'];

        $attachment = [
            'post_mime_type' => $type,
            'post_title'     => sanitize_file_name(basename($path)),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];

        $attach_id = wp_insert_attachment($attachment, $path);
        if (is_wp_error($attach_id)) return $attach_id;

        if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata($attach_id, $path);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
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

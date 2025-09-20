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
        // Make sure our assets can be enqueued (loaded in main plugin file via Assets::init()).
        add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue_form_assets']);
    }

    /** Only enqueue on pages where the shortcode is present. */
    public static function maybe_enqueue_form_assets() {
        if (!is_singular()) return;
        global $post;
        if (!$post) return;
        if (has_shortcode($post->post_content, self::SHORTCODE)) {
            // Scripts/styles are enqueued by \Oval15\Core\Assets::enqueue()
            // We only add a minimal inline overlay style here (no duplication risk).
            $css = '#oval15-loading{display:none;position:fixed;inset:0;background:rgba(255,255,255,.7);z-index:9999}
                    #oval15-loading .spinner{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:16px}';
            wp_add_inline_style('select2', $css); // piggyback on an already-enqueued handle
        }
    }

    public static function shortcode($atts = []) {
        if (!is_user_logged_in()) {
            return '<div class="woocommerce-info">Please sign in to complete your registration.</div>';
        }

        $u = wp_get_current_user();
        $data = self::get_prefill($u->ID);

        ob_start();
        ?>
        <div id="oval15-loading"><div class="spinner">Submitting… please wait</div></div>

        <form class="oval15-complete-registration" method="post" enctype="multipart/form-data">
            <?php wp_nonce_field(self::NONCE, self::NONCE); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_POST); ?>">
            <?php
            // If arriving from thank-you with order context, preserve it.
            if (!empty($_GET['order']) && !empty($_GET['key'])): ?>
                <input type="hidden" name="order_id" value="<?php echo esc_attr(absint($_GET['order'])); ?>">
                <input type="hidden" name="order_key" value="<?php echo esc_attr(wp_unslash($_GET['key'])); ?>">
            <?php endif; ?>

            <h3>Personal</h3>
            <div class="form-group">
                <p><label for="f_name">First Name <span class="required">*</span></label></p>
                <p><input type="text" id="f_name" name="f_name" class="form-control" required value="<?php echo esc_attr($data['f_name']); ?>"></p>
            </div>
            <div class="form-group">
                <p><label for="l_name">Last Name <span class="required">*</span></label></p>
                <p><input type="text" id="l_name" name="l_name" class="form-control" required value="<?php echo esc_attr($data['l_name']); ?>"></p>
            </div>
            <div class="form-group">
                <p><label for="email">Email <span class="required">*</span></label></p>
                <p><input type="email" id="email" name="email" class="form-control" required readonly value="<?php echo esc_attr($u->user_email); ?>"></p>
            </div>
            <div class="form-group">
                <p><label for="gender">Gender <span class="required">*</span></label></p>
                <p>
                    <select id="gender" name="gender" class="input-text" required>
                        <?php foreach (['Male','Female'] as $g): ?>
                            <option value="<?php echo esc_attr($g); ?>" <?php selected($data['gender'], $g); ?>><?php echo esc_html($g); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
            </div>
            <div class="form-group">
                <p><label for="dob">Date Of Birth</label></p>
                <p><input type="date" id="dob" name="dob" class="input-text" value="<?php echo esc_attr($data['dob']); ?>"></p>
            </div>

            <p><label for="main-position">Main Position</label></p>
            <p>
                <select id="main-position" name="main-position" class="input-text">
                    <?php echo self::options_positions($data['main-position'], true); ?>
                </select>
            </p>

            <p><label for="secondary-position">Secondary Position(s)</label></p>
            <p>
                <select id="secondary-position" name="secondary-position[]" class="input-text select2_box" multiple>
                    <?php echo self::options_positions($data['secondary-position']); ?>
                </select>
            </p>

            <div class="form-group">
                <p><label for="lop">Level of player <span class="required">*</span></label></p>
                <p>
                    <select id="lop" name="lop" class="input-text" required>
                        <?php foreach (['Amateur','Semi-Pro','Professional'] as $lvl): ?>
                            <option value="<?php echo esc_attr($lvl); ?>" <?php selected($data['lop'], $lvl); ?>><?php echo esc_html($lvl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
            </div>

            <div class="form-group">
                <p><label for="c_number">Contact number <span class="required">*</span></label></p>
                <p>
                    <input type="tel" id="c_number" name="c_number" class="form-control" required
                           value="<?php echo esc_attr($data['c_number']); ?>" autocomplete="off">
                </p>
                <input type="hidden" name="country_code" id="country-code" value="<?php echo esc_attr($data['country_code']); ?>">
            </div>

            <div class="form-group">
                <p><label for="nation">Nationality <span class="required">*</span></label></p>
                <p><?php echo self::select_country('nation', $data['nation'], false, ['required' => true]); ?></p>
            </div>

            <div class="form-group">
                <p><label for="passport">Passport <span class="required">*</span></label></p>
                <p><?php echo self::select_country('passport[]', (array)$data['passport'], true, ['required' => true, 'id' => 'passport']); ?></p>
            </div>

            <div class="form-group">
                <p><label for="current_location_country">Current Location (Country) <span class="required">*</span></label></p>
                <p><?php echo self::select_country('current_location_country', $data['current_location_country'], false, ['required' => true]); ?></p>
            </div>

            <div class="form-group">
                <p><label for="weight">Weight (In KG) <span class="required">*</span></label></p>
                <p><input type="number" id="weight" name="weight" class="form-control" required value="<?php echo esc_attr($data['weight']); ?>"></p>
            </div>

            <div class="form-group">
                <p><label for="height">Height (In CM) <span class="required">*</span></label></p>
                <p><input type="number" id="height" name="height" class="form-control" min="150" max="1000" step="1" required value="<?php echo esc_attr($data['height']); ?>"></p>
            </div>

            <div class="form-group" style="display:flex;gap:10px;margin:0;">
                <div>
                    <p><label for="available_from_year">Available From (Year)</label></p>
                    <p><input type="number" id="available_from_year" name="available_from_year" class="form-control" value="<?php echo esc_attr($data['available_from_year']); ?>" placeholder="<?php echo esc_attr(date('Y')); ?>"></p>
                </div>
                <div>
                    <p><label for="available_from_month">Available From (Month)</label></p>
                    <p>
                        <select id="available_from_month" name="available_from_month" class="input-text">
                            <option value="">--</option>
                            <?php foreach (range(1,12) as $m): ?>
                                <option value="<?php echo $m; ?>" <?php selected(intval($data['available_from_month']), $m); ?>>
                                    <?php echo esc_html(date('F', mktime(0,0,0,$m,1))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                </div>
            </div>

            <h3>Current playing history</h3>
            <?php for ($i=1; $i<=3; $i++): ?>
                <div class="form-group" style="display:flex;gap:10px;margin:0;">
                    <div>
                        <p><label for="club_<?php echo $i; ?>">Club Name<?php echo $i===1 ? ' <span class="required">*</span>':''; ?></label></p>
                        <p><input type="text" id="club_<?php echo $i; ?>" name="club_<?php echo $i; ?>" class="form-control" <?php echo $i===1?'required':''; ?> value="<?php echo esc_attr($data["club_{$i}"]); ?>"></p>
                    </div>
                    <div>
                        <p><label for="tournament_<?php echo $i; ?>">League<?php echo $i===1 ? ' <span class="required">*</span>':''; ?></label></p>
                        <p><input type="text" id="tournament_<?php echo $i; ?>" name="tournament_<?php echo $i; ?>" class="form-control" <?php echo $i===1?'required':''; ?> value="<?php echo esc_attr($data["tournament_{$i}"]); ?>"></p>
                    </div>
                    <div>
                        <p><label for="period_<?php echo $i; ?>">Period<?php echo $i===1 ? ' <span class="required">*</span>':''; ?></label></p>
                        <p><input type="text" id="period_<?php echo $i; ?>" name="period_<?php echo $i; ?>" class="form-control" <?php echo $i===1?'required':''; ?> value="<?php echo esc_attr($data["period_{$i}"]); ?>"></p>
                    </div>
                </div>
            <?php endfor; ?>

            <h3 class="mb-0 mt-4">Player Profile</h3>
            <p><small>Tell us about your strengths as a player, notable achievements, any representative teams, etc.</small>
            <br><a class="custom__link" target="_blank" href="/news/how-to-sell-yourself-in-your-player-profile/">How To Sell Yourself In Your Player Profile</a></p>

            <div class="form-group">
                <p><label for="profile">Profile <span class="required">*</span></label></p>
                <p><textarea id="profile" name="p_profile" class="p-profile-count" cols="50" rows="10"><?php echo esc_textarea($data['p_profile']); ?></textarea></p>
            </div>

            <h3>Media (optional)</h3>
            <div class="form-group">
                <p><label for="upload_photo">Profile Photo</label></p>
                <p><input type="file" id="upload_photo" name="upload_photo" accept="image/*"></p>
            </div>
            <div class="form-group">
                <p><label for="v_upload_id">Highlight Video (upload)</label></p>
                <p><input type="file" id="v_upload_id" name="v_upload_id" accept="video/*"></p>
            </div>
            <div class="form-group">
                <p><label for="yt_video">Or Video URL (YouTube/Vimeo)</label></p>
                <p><input type="url" id="yt_video" name="yt_video" class="form-control" value="<?php echo esc_attr($data['yt_video']); ?>"></p>
            </div>

            <p><button type="submit" class="button button-primary">Complete Registration</button></p>
        </form>
        <?php
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
        $clean = self::sanitize_input($_POST);

        // Save user core fields
        wp_update_user([
            'ID'         => $user_id,
            'first_name' => $clean['f_name'],
            'last_name'  => $clean['l_name'],
            // do NOT change email; it’s readonly here
        ]);

        // Save meta (keep live keys)
        $map = self::meta_map();

        foreach ($map as $post_key => $meta_key) {
            if (!array_key_exists($post_key, $clean)) continue;
            update_user_meta($user_id, $meta_key, $clean[$post_key]);
        }

        // Array fields
        update_user_meta($user_id, 'secondary-position', $clean['secondary-position']);
        update_user_meta($user_id, 'passport', $clean['passport']);

        // Country code with phone stored separately (and combined convenience string)
        if (!empty($clean['country_code']) && !empty($clean['c_number'])) {
            $combined = trim($clean['country_code']) . ' ' . trim($clean['c_number']);
            update_user_meta($user_id, 'contact_number_combined', $combined);
        }

        // Handle profile photo upload (optional)
        if (!empty($_FILES['upload_photo']['name'])) {
            $photo_id = self::handle_upload('upload_photo', ['image/jpeg','image/png','image/webp']);
            if (!is_wp_error($photo_id)) {
                update_user_meta($user_id, 'profile_photo_id', intval($photo_id));
            }
        }

        // Handle video: file OR url
        $video_attachment_id = null;
        if (!empty($_FILES['v_upload_id']['name'])) {
            // Prefer your Video helper if present
            if (class_exists('\Oval15\Core\Media\Video') && method_exists('\Oval15\Core\Media\Video', 'handle_upload')) {
                $video_attachment_id = Video::handle_upload('v_upload_id'); // should return attachment ID or WP_Error
            } else {
                $video_attachment_id = self::handle_upload('v_upload_id', ['video/mp4','video/quicktime','video/webm','video/ogg']);
            }
            if (!is_wp_error($video_attachment_id) && $video_attachment_id) {
                update_user_meta($user_id, 'v_upload_id', intval($video_attachment_id));
            }
        }
        if (!empty($clean['yt_video'])) {
            update_user_meta($user_id, 'yt_video', esc_url_raw($clean['yt_video']));
        }

        // WooCommerce order binding (if present)
        $order_id = !empty($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $order_key = !empty($_POST['order_key']) ? wc_clean(wp_unslash($_POST['order_key'])) : '';
        if ($order_id && $order_key) {
            $order = wc_get_order($order_id);
            if ($order && hash_equals($order->get_order_key(), $order_key)) {
                // Attach customer ID if missing
                if (!$order->get_customer_id()) {
                    $order->set_customer_id($user_id);
                    $order->save();
                }
                update_user_meta($user_id, '_oval15_reg_completed_at', time());
                do_action('oval15/registration_completed', $user_id, $order_id);
            }
        } else {
            // Still emit a completion signal even without order context
            update_user_meta($user_id, '_oval15_reg_completed_at', time());
            do_action('oval15/registration_completed', $user_id, 0);
        }

        // Success
        wc_add_notice(__('Registration details saved.'), 'success');

        // Redirect back to same page to avoid resubmission + to let notices render
        wp_safe_redirect(add_query_arg(['updated' => 1], wp_get_referer() ?: home_url('/')));
        exit;
    }

    /** Build prefill from user meta */
    private static function get_prefill($user_id) {
        $u = get_userdata($user_id);

        $getm = function($k,$d='') use($user_id){ $v=get_user_meta($user_id,$k,true); return $v===''?$d:$v; };

        $prefill = [
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

        return $prefill;
    }

    /** Sanitize incoming POST */
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

    /** Map POST keys → user_meta keys (kept to your live schema) */
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

    /** Upload helper (fallback if Video::handle_upload not used) */
    private static function handle_upload($field, $mimes = []) {
        if (empty($_FILES[$field]['name'])) return new \WP_Error('no_file', 'No file uploaded');

        require_once ABSPATH . 'wp-admin/includes/file.php';
        add_filter('upload_mimes', function($mime_types) use ($mimes){
            if (!$mimes) return $mime_types;
            // Allow the provided mimes in addition to defaults
            foreach ($mimes as $mime) {
                // nothing: WP expects ext=>mime pairs; rely on core since we don’t know ext here
            }
            return $mime_types;
        });

        $overrides = ['test_form' => false];
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
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $file['file']));
        return $attach_id;
    }

    /** Position options */
    private static function options_positions($selected = [], $with_placeholder = false) {
        $positions = [
            'Prop (1)','Hooker','Prop (3)','Lock (4)','Lock (5)','Flank (Openside)','Flank (Blindside)',
            'No 8','Scrumhalf','Flyhalf','Inside Centre (12)','Outside Centre (13)','Wing','Fullback','Utility Back'
        ];
        $selected = (array)$selected;
        $out = '';
        if ($with_placeholder) {
            $out .= '<option>' . esc_html('<-- Select Main Position -->') . '</option>';
        }
        foreach ($positions as $p) {
            $sel = in_array($p, $selected, true) ? ' selected' : '';
            $out .= '<option value="'.esc_attr($p).'"'.$sel.'>'.esc_html($p).'</option>';
        }
        return $out;
    }

    /** Country selects (single or multiple) */
    private static function select_country($name, $value, $multiple = false, $attrs = []) {
        $countries = self::countries();
        $id = !empty($attrs['id']) ? $attrs['id'] : sanitize_title($name);
        $extra = '';
        foreach ($attrs as $k=>$v) {
            if ($k === 'id') continue;
            $extra .= ' ' . esc_attr($k) . '="' . esc_attr($v) . '"';
        }
        $multiple_attr = $multiple ? ' multiple class="input-text select2_box"' : ' class="input-text select2_box"';
        $html = '<select name="'.esc_attr($name).'" id="'.esc_attr($id).'"'.$multiple_attr.$extra.'>';
        $vals = (array)$value;
        foreach ($countries as $c) {
            $sel = in_array($c, $vals, true) ? ' selected' : '';
            $html .= '<option value="'.esc_attr($c).'"'.$sel.'>'.esc_html($c).'</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private static function countries() {
        // Curated list you’re using
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
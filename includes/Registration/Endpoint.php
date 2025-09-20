<?php
namespace Oval15\Core\Registration;

use Oval15\Core\Media\Video;

if (!defined('ABSPATH')) exit;

class Endpoint {
    const SLUG         = 'oval15-complete-registration';
    const SHORTCODE    = 'oval15_complete_registration';
    const NONCE        = 'oval15_complete_registration_nonce';
    const ACTION_POST  = 'oval15_complete_registration_submit';

    public static function init() {
        // Shortcode aliases (so any of these render the form)
        add_shortcode(self::SHORTCODE, [__CLASS__, 'shortcode']);
        add_shortcode('complete_registration', [__CLASS__, 'shortcode']);
        add_shortcode('complete-registration', [__CLASS__, 'shortcode']);

        // Handle POST
        add_action('template_redirect', [__CLASS__, 'maybe_handle_post']);

        // Inject shortcode if page content doesn’t include it
        add_filter('the_content', [__CLASS__, 'inject_shortcode_fallback']);

        // Minimal CSS (no external libraries)
        add_action('wp_head', function () {
            if (!is_singular()) return;
            $css = '#oval15-loading{display:none;position:fixed;inset:0;background:rgba(255,255,255,.7);z-index:9999}
#oval15-loading .spinner{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:16px}
.oval15-order-context{margin:15px 0 20px;padding:12px 14px;border:1px solid #ddd;border-radius:6px;background:#fafafa}
.oval15-complete-registration .form-group{margin:10px 0}
.oval15-complete-registration label{font-weight:600}
.oval15-complete-registration input[type=text],
.oval15-complete-registration input[type=number],
.oval15-complete-registration input[type=email],
.oval15-complete-registration input[type=tel],
.oval15-complete-registration input[type=url],
.oval15-complete-registration input[type=date],
.oval15-complete-registration select,
.oval15-complete-registration textarea{width:100%;max-width:520px}
';
            echo "<style>$css</style>";
        });

        // Minimal submit overlay JS (no CKEditor/Select2/etc.)
        add_action('wp_footer', function () {
            if (!is_singular()) return;
            ?>
<script>
document.addEventListener('DOMContentLoaded',function(){
  var forms = document.querySelectorAll('.oval15-complete-registration');
  forms.forEach(function(f){
    f.addEventListener('submit', function(){
      var btn = f.querySelector('button[type="submit"]');
      if(btn){ btn.disabled = true; btn.textContent = 'Submitting…'; }
      var overlay = document.getElementById('oval15-loading');
      if(overlay){ overlay.style.display = 'block'; }
    });
  });
});
</script>
<?php
        });
    }

    /** If “Complete Registration” page exists without shortcode, inject ours. */
    public static function inject_shortcode_fallback($content) {
        if (!is_singular()) return $content;
        global $post;
        if (!$post) return $content;

        $is_target = (get_post_field('post_name', $post) === 'complete-registration')
                  || (stripos($post->post_title ?? '', 'complete registration') !== false);

        if (!$is_target) return $content;

        $has = has_shortcode($content, self::SHORTCODE)
            || has_shortcode($content, 'complete_registration')
            || has_shortcode($content, 'complete-registration');

        if ($has) return $content;

        // Append our shortcode so the page always renders the form
        return $content . "\n\n[" . self::SHORTCODE . "]";
    }

    /** Shortcode renderer */
    public static function shortcode($atts = []) {
        // Validate Woo order context (optional)
        $order = null;
        if (!empty($_GET['order']) && !empty($_GET['key'])) {
            $order_id  = absint($_GET['order']);
            $order_key = wc_clean(wp_unslash($_GET['key']));
            $maybe     = wc_get_order($order_id);
            if ($maybe && hash_equals($maybe->get_order_key(), $order_key)) {
                $order = $maybe;
            }
        }

        if (!is_user_logged_in()) {
            ob_start();
            if ($order instanceof \WC_Order): ?>
                <div class="oval15-order-context">
                    <strong>Order #<?php echo esc_html($order->get_order_number()); ?></strong>
                    — <?php echo esc_html(wc_format_datetime($order->get_date_created())); ?> —
                    Total: <?php echo wp_kses_post($order->get_formatted_order_total()); ?>
                </div>
            <?php endif; ?>
            <div class="woocommerce-info">
                Please sign in to complete your registration.
                <?php
                $login_url = wc_get_page_permalink('myaccount');
                if ($order instanceof \WC_Order) {
                    $login_url = add_query_arg(['order'=>$order->get_id(),'key'=>$order->get_order_key()], $login_url);
                }
                ?>
                <a class="button" href="<?php echo esc_url($login_url); ?>" style="margin-left:8px;">Sign in</a>
            </div>
            <?php
            return ob_get_clean();
        }

        $u          = wp_get_current_user();
        $data       = self::get_prefill($u->ID);
        $countries  = self::countries();
        $positions  = self::positions();

        ob_start();
        include __DIR__ . '/../../templates/complete-registration-form.php';
        return ob_get_clean();
    }

    /** Handle submission */
    public static function maybe_handle_post() {
        if (empty($_POST['action']) || $_POST['action'] !== self::ACTION_POST) return;

        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) {
            wc_add_notice(__('Security check failed. Please try again.'), 'error');
            return;
        }
        if (!is_user_logged_in()) {
            wc_add_notice(__('You must be logged in.'), 'error');
            return;
        }

        $user_id = get_current_user_id();
        $clean   = self::sanitize_input($_POST);

        // Core user fields
        wp_update_user([
            'ID'         => $user_id,
            'first_name' => $clean['f_name'],
            'last_name'  => $clean['l_name'],
        ]);

        // Meta
        foreach (self::meta_map() as $post_key => $meta_key) {
            if (array_key_exists($post_key, $clean)) {
                update_user_meta($user_id, $meta_key, $clean[$post_key]);
            }
        }
        update_user_meta($user_id, 'secondary-position', $clean['secondary-position']);
        update_user_meta($user_id, 'passport', $clean['passport']);

        if (!empty($clean['country_code']) && !empty($clean['c_number'])) {
            update_user_meta($user_id, 'contact_number_combined', trim($clean['country_code']).' '.trim($clean['c_number']));
        }

        // Photo (optional)
        if (!empty($_FILES['upload_photo']['name'])) {
            $photo_id = self::handle_upload_generic($_FILES['upload_photo']);
            if (!is_wp_error($photo_id)) {
                update_user_meta($user_id, 'profile_photo_id', (int) $photo_id);
            }
        }

        // Video (optional) — pass the actual $_FILES array to Video::handle_upload
        if (!empty($_FILES['v_upload_id']['name'])) {
            if (class_exists('\Oval15\Core\Media\Video')) {
                $vid_id = Video::handle_upload($_FILES['v_upload_id'], 100);
            } else {
                $vid_id = self::handle_upload_generic($_FILES['v_upload_id']);
            }
            if (!is_wp_error($vid_id) && $vid_id) {
                update_user_meta($user_id, 'v_upload_id', (int) $vid_id);
            }
        }
        if (!empty($clean['yt_video'])) {
            update_user_meta($user_id, 'yt_video', esc_url_raw($clean['yt_video']));
        }

        // Woo order context (optional)
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

        $redirect = remove_query_arg(['updated'], wp_get_referer() ?: home_url('/'));
        $redirect = add_query_arg(['updated'=>1], $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    /** Prefill */
    private static function get_prefill($user_id) {
        $u = get_userdata($user_id);
        $getm = function($k,$d='') use($user_id){ $v=get_user_meta($user_id,$k,true); return ($v===''? $d : $v); };

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

    /** Sanitize */
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
            $clean["club_{$i}"]       = $sf("club_{$i}");
            $clean["tournament_{$i}"] = $sf("tournament_{$i}");
            $clean["period_{$i}"]     = $sf("period_{$i}");
        }

        $clean['p_profile'] = isset($src['p_profile']) ? wp_kses_post(wp_unslash($src['p_profile'])) : '';
        $clean['yt_video']  = isset($src['yt_video']) ? esc_url_raw(wp_unslash($src['yt_video'])) : '';

        return $clean;
    }

    /** Meta map */
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

    /** Generic upload helper */
    private static function handle_upload_generic(array $file) {
        if (empty($file['name'])) return new \WP_Error('no_file', 'No file uploaded');
        if (!function_exists('wp_handle_upload')) require_once ABSPATH . 'wp-admin/includes/file.php';
        if (!function_exists('wp_insert_attachment')) require_once ABSPATH . 'wp-admin/includes/media.php';
        if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';

        $overrides = ['test_form' => false];
        $movefile  = wp_handle_upload($file, $overrides);
        if (isset($movefile['error'])) return new \WP_Error('upload_error', $movefile['error']);

        $attachment = [
            'post_mime_type' => $movefile['type'],
            'post_title'     => sanitize_file_name(basename($movefile['file'])),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];
        $attach_id = wp_insert_attachment($attachment, $movefile['file']);
        if (is_wp_error($attach_id)) return $attach_id;

        $meta = wp_generate_attachment_metadata($attach_id, $movefile['file']);
        wp_update_attachment_metadata($attach_id, $meta);
        return $attach_id;
    }

    /** Positions */
    public static function positions() {
        return [
            'Prop (1)','Hooker','Prop (3)','Lock (4)','Lock (5)','Flank (Openside)','Flank (Blindside)',
            'No 8','Scrumhalf','Flyhalf','Inside Centre (12)','Outside Centre (13)','Wing','Fullback','Utility Back'
        ];
    }

    /** Countries (curated) */
    public static function countries() {
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
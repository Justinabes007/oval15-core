<?php
/**
 * Plugin Name: Oval15 Core
 * Description: Core workflows for Oval15 (Pay-First checkout, tokenized Complete Registration, compatibility shims, and admin settings).
 * Version: 0.4.2
 * Author: Oval15
 */

if (!defined('ABSPATH')) exit;

define('OVAL15_CORE_VERSION', '0.4.2');

require __DIR__ . '/includes/Admin/Settings.php';
require __DIR__ . '/includes/Compat/UnhookTheme.php';
require __DIR__ . '/includes/Checkout/Flow.php';
require __DIR__ . '/includes/Registration/Endpoint.php';
require __DIR__ . '/includes/Notifications/Emails.php';
require __DIR__ . '/includes/Admin/Approvals.php'; 
require __DIR__ . '/includes/Integrations/Webhooks.php'; 
require __DIR__ . '/includes/Registration/ProfileEdit.php';
require __DIR__ . '/includes/Media/Video.php';
require __DIR__ . '/includes/Checkout/ThankyouRedirect.php';

add_action('plugins_loaded', function () {
    \Oval15\Core\Admin\Settings::init();

    $opt = get_option(\Oval15\Core\Admin\Settings::OPTION, []);
    $enabled = isset($opt['pay_first']) ? (bool) $opt['pay_first'] : (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'staging');

    if (!defined('OVAL15_PAY_FIRST')) {
        define('OVAL15_PAY_FIRST', apply_filters('oval15/pay_first_enabled', $enabled));
    }

    if (OVAL15_PAY_FIRST) {
        \Oval15\Core\Compat\UnhookTheme::init();
    }

    \Oval15\Core\Checkout\ThankyouRedirect::init();
    \Oval15\Core\Checkout\Flow::init();
    \Oval15\Core\Registration\Endpoint::init();
    \Oval15\Core\Notifications\Emails::init();
    \Oval15\Core\Admin\Approvals::init();
    \Oval15\Core\Integrations\Webhooks::init();
    \Oval15\Core\Registration\ProfileEdit::init();
    \Oval15\Core\Assets::init(); // enqueue assets
});

namespace Oval15\Core;

if (!defined('ABSPATH')) exit;

class Assets {
    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('wp_footer', [__CLASS__, 'overlay_html']); // inject overlay markup
    }

    public static function enqueue() {
        // Only load on pages where our shortcodes appear
        if (!is_singular()) return;

        global $post;
        if (!$post) return;
        $content = $post->post_content;
        if (strpos($content, 'oval15_complete_registration') === false &&
            strpos($content, 'oval15_profile_edit') === false) {
            return; // no need to load
        }

        // Select2
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0');
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true);

        // intl-tel-input
        wp_enqueue_style('intl-tel-input', 'https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/css/intlTelInput.css', [], '18.2.1');
        wp_enqueue_script('intl-tel-input', 'https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/intlTelInput.min.js', [], '18.2.1', true);

        // CKEditor (only include once)
        wp_enqueue_script('ckeditor5', 'https://cdn.ckeditor.com/ckeditor5/41.4.2/classic/ckeditor.js', [], '41.4.2', true);

        // Our inline init
        wp_add_inline_script('ckeditor5', self::init_js());
    }

    private static function init_js() {
        return <<<JS
        (function($){
            // Select2 safe init
            if ($.fn.select2) {
                $('.select2_box').each(function(){
                    try { $(this).select2(); } catch(e) { console.warn('Select2 failed', e); }
                });
            }

            // CKEditor safe init
            if (window.ClassicEditor && typeof ClassicEditor.create === 'function') {
                var el = document.getElementById('profile');
                if (el) {
                    ClassicEditor.create(el).catch(function(e){ console.warn('CKEditor error', e); });
                }
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

            // Loading overlay on submit
            $('.oval15-complete-registration').on('submit', function(){
                var $btn = $(this).find('button[type=submit]');
                $btn.prop('disabled', true).text('Submitting…');
                $('#oval15-loading').fadeIn(200);
            });
        })(jQuery);
JS;
    }

    public static function overlay_html() {
        // Add only on pages where our form is present
        if (!is_singular()) return;
        global $post;
        if (!$post) return;
        $content = $post->post_content;
        if (strpos($content, 'oval15_complete_registration') === false &&
            strpos($content, 'oval15_profile_edit') === false) {
            return;
        }

        echo '<div id="oval15-loading" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;
            background:rgba(255,255,255,0.85);z-index:9999;text-align:center;padding-top:20%;">
            <h2>Submitting, please wait…</h2>
        </div>';
    }
}
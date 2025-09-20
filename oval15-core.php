<?php
namespace Oval15\Core\Registration;

if (!defined('ABSPATH')) exit;

use Oval15\Core\Media\Video;

class Endpoint {

    public static function init() {
        add_action('init', [__CLASS__, 'add_endpoint']);
        add_filter('query_vars', [__CLASS__, 'query_vars']);
        add_action('template_redirect', [__CLASS__, 'maybe_handle_post']);
        add_filter('the_content', [__CLASS__, 'render_form']);
    }

    public static function add_endpoint() {
        add_rewrite_endpoint('complete-registration', EP_ROOT | EP_PAGES);
    }

    public static function query_vars($vars) {
        $vars[] = 'complete-registration';
        return $vars;
    }

    /**
     * Handle form submission
     */
    public static function maybe_handle_post() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        if (empty($_POST['oval15_complete_registration_nonce']) || !wp_verify_nonce($_POST['oval15_complete_registration_nonce'], 'oval15_complete_registration')) {
            return;
        }

        $user_id = get_current_user_id();
        if (!$user_id) return;

        $fields = [
            'f_name'   => sanitize_text_field($_POST['f_name'] ?? ''),
            'l_name'   => sanitize_text_field($_POST['l_name'] ?? ''),
            'gender'   => sanitize_text_field($_POST['gender'] ?? ''),
            'dob'      => sanitize_text_field($_POST['dob'] ?? ''),
            'main-position' => sanitize_text_field($_POST['main-position'] ?? ''),
            'secondary-position' => array_map('sanitize_text_field', $_POST['secondary-position'] ?? []),
            'lop'      => sanitize_text_field($_POST['lop'] ?? ''),
            'c_number' => sanitize_text_field($_POST['c_number'] ?? ''),
            'country_code' => sanitize_text_field($_POST['country_code'] ?? ''),
            'nation'   => sanitize_text_field($_POST['nation'] ?? ''),
            'passport' => array_map('sanitize_text_field', $_POST['passport'] ?? []),
            'current_location_country' => sanitize_text_field($_POST['current_location_country'] ?? ''),
            'weight'   => intval($_POST['weight'] ?? 0),
            'height'   => intval($_POST['height'] ?? 0),
            'club_1'   => sanitize_text_field($_POST['club_1'] ?? ''),
            'tournament_1' => sanitize_text_field($_POST['tournament_1'] ?? ''),
            'period_1' => sanitize_text_field($_POST['period_1'] ?? ''),
            'club_2'   => sanitize_text_field($_POST['club_2'] ?? ''),
            'tournament_2' => sanitize_text_field($_POST['tournament_2'] ?? ''),
            'period_2' => sanitize_text_field($_POST['period_2'] ?? ''),
            'club_3'   => sanitize_text_field($_POST['club_3'] ?? ''),
            'tournament_3' => sanitize_text_field($_POST['tournament_3'] ?? ''),
            'period_3' => sanitize_text_field($_POST['period_3'] ?? ''),
            'p_profile' => wp_kses_post($_POST['p_profile'] ?? ''),
            'v_link'   => esc_url_raw($_POST['v_link'] ?? ''),
            'level'    => array_map('sanitize_text_field', $_POST['level'] ?? []),
            'interested_country' => array_map('sanitize_text_field', $_POST['interested_country'] ?? []),
            'degree'   => array_map('sanitize_text_field', $_POST['degree'] ?? []),
            'fitness_stats' => sanitize_text_field($_POST['fitness_stats'] ?? ''),
            'months'   => sanitize_text_field($_POST['months'] ?? ''),
            'years'    => sanitize_text_field($_POST['years'] ?? ''),
            'reason'   => sanitize_text_field($_POST['reason'] ?? ''),
        ];

        foreach ($fields as $key => $val) {
            update_user_meta($user_id, $key, $val);
        }

        // Handle profile photo
        if (!empty($_FILES['p_photo']['name'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $upload = wp_handle_upload($_FILES['p_photo'], ['test_form' => false]);
            if (!isset($upload['error'])) {
                update_user_meta($user_id, 'p_photo', esc_url_raw($upload['url']));
            }
        }

        // Handle video upload
        if (!empty($_FILES['v_upload_id']['name'])) {
            Video::handle_upload($_FILES['v_upload_id']);
        }

        wp_redirect(add_query_arg(['updated' => 'true'], wp_get_referer()));
        exit;
    }

    /**
     * Render form on the endpoint
     */
    public static function render_form($content) {
        global $wp_query;

        if (!isset($wp_query->query_vars['complete-registration'])) return $content;

        ob_start();

        $order_id = isset($_GET['order']) ? absint($_GET['order']) : 0;
        if ($order_id && ($order = wc_get_order($order_id))) {
            echo '<div class="oval15-order-summary">';
            echo '<h2>Order Information</h2>';
            echo '<p><strong>Order #:</strong> ' . esc_html($order->get_order_number()) . '</p>';
            echo '<p><strong>Total:</strong> ' . wp_kses_post($order->get_formatted_order_total()) . '</p>';
            echo '<p><strong>Status:</strong> ' . esc_html(wc_get_order_status_name($order->get_status())) . '</p>';
            echo '</div>';
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            echo '<p>You must be logged in to complete registration.</p>';
            return ob_get_clean();
        }

        $meta = get_user_meta($user_id);

        include __DIR__ . '/templates/complete-registration-form.php';

        return ob_get_clean();
    }
}
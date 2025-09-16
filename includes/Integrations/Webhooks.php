<?php
namespace Oval15\Core\Integrations;

if (!defined('ABSPATH')) exit;

class Webhooks {
    const OPT   = 'oval15_webhooks';
    const GROUP = 'oval15_webhooks';

    // Retry backoff: 1m, 5m, 30m, 2h, 1d
    private static $backoff = [60, 300, 1800, 7200, 86400];

    /* =========================
     * Topics supported
     * ========================= */
    public static function topics() {
        return [
            'registration.completed' => 'Player finished Complete Registration',
            'user.approved'          => 'User approved',
            'user.declined'          => 'User declined',
            'order.completed'        => 'WooCommerce order completed/thank-you',
            'email.sent'             => 'Plugin email sent (welcome/decline)',
            'profile.updated'        => 'Player profile updated',
        ];
    }

    public static function init() {
        // Admin UI
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_oval15_webhook_save', [__CLASS__, 'handle_save']);
        add_action('admin_post_oval15_webhook_delete', [__CLASS__, 'handle_delete']);

        // Generic emitter and queued delivery
        add_action('oval15/event_emit', [__CLASS__, 'emit'], 10, 2);
        if (function_exists('as_enqueue_async_action')) {
            add_action('oval15_deliver_webhook', [__CLASS__, 'deliver_action'], 10, 4);
        }

        // Event sources (adapters)
        add_action('oval15/registration_completed', [__CLASS__, 'on_registration_completed'], 10, 2);
        add_action('oval15/user_approved',          [__CLASS__, 'on_user_approved'], 10, 2);
        add_action('oval15/user_declined',          [__CLASS__, 'on_user_declined'], 10, 2);
        add_action('oval15/welcome_email_sent',     [__CLASS__, 'on_welcome_sent'], 10, 2);
        add_action('oval15/decline_email_sent',     [__CLASS__, 'on_decline_sent'], 10, 2);
        add_action('oval15/profile_updated',        [__CLASS__, 'on_profile_updated'], 10, 2);
        add_action('woocommerce_thankyou',          [__CLASS__, 'on_order_completed'], 20, 1);
    }

    /* =========================
     * Admin UI
     * ========================= */
    public static function menu() {
        add_submenu_page(
            'woocommerce',
            'Oval15 Webhooks',
            'Oval15 Webhooks',
            'manage_woocommerce',
            'oval15_webhooks',
            [__CLASS__, 'render_admin']
        );
    }

    public static function render_admin() {
        if (!current_user_can('manage_woocommerce')) return;
        $eps = get_option(self::OPT, []);
        if (!is_array($eps)) $eps = [];
        $topics = self::topics();

        echo '<div class="wrap"><h1>Oval15 Webhooks</h1>';
        echo '<p>Send signed JSON payloads to external endpoints (Zapier/Make, etc.) in the background with retries.</p>';

        // List
        if (empty($eps)) {
            echo '<p><em>No endpoints configured.</em></p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>URL</th><th>Topics</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
            foreach ($eps as $i => $ep) {
                $url = esc_url($ep['url'] ?? '');
                $enabled = !empty($ep['enabled']) ? 'Enabled' : 'Disabled';
                $t = array_intersect_key($topics, array_flip((array)($ep['topics'] ?? [])));
                $tlist = $t ? implode(', ', array_keys($t)) : '<em>None</em>';
                $del = wp_nonce_url(admin_url('admin-post.php?action=oval15_webhook_delete&idx='.$i), 'oval15_webhook_delete_'.$i);
                echo '<tr><td><code>'.$url.'</code></td><td>'.$tlist.'</td><td>'.$enabled.'</td><td><a class="button-link delete" href="'.esc_url($del).'">Delete</a></td></tr>';
            }
            echo '</tbody></table>';
        }

        // Add form
        $save = wp_nonce_url(admin_url('admin-post.php?action=oval15_webhook_save'), 'oval15_webhook_save');
        echo '<h2 style="margin-top:24px">Add endpoint</h2>';
        echo '<form method="post" action="'.esc_url($save).'" style="max-width:760px">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="oval15_wh_url">Endpoint URL</label></th><td><input type="url" class="regular-text" name="url" id="oval15_wh_url" required placeholder="https://hooks.zapier.com/..." /></td></tr>';
        echo '<tr><th scope="row"><label for="oval15_wh_secret">Secret</label></th><td><input type="text" class="regular-text" name="secret" id="oval15_wh_secret" placeholder="Shared secret for HMAC signature" /></td></tr>';
        echo '<tr><th scope="row">Topics</th><td>';
        foreach ($topics as $k => $label) {
            echo '<label style="display:block;margin:2px 0"><input type="checkbox" name="topics[]" value="'.esc_attr($k).'"> '.esc_html($k).' — '.esc_html($label).'</label>';
        }
        echo '</td></tr>';
        echo '<tr><th scope="row">Enabled</th><td><label><input type="checkbox" name="enabled" value="1" checked> Enabled</label></td></tr>';
        echo '</tbody></table>';
        submit_button('Save Endpoint');
        echo '</form>';

        echo '</div>';
    }

    public static function handle_save() {
        if (!current_user_can('manage_woocommerce')) wp_die('Insufficient permissions.');
        check_admin_referer('oval15_webhook_save');

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        if (!$url) wp_safe_redirect(admin_url('admin.php?page=oval15_webhooks'));

        $secret = isset($_POST['secret']) ? sanitize_text_field($_POST['secret']) : '';
        $topics = isset($_POST['topics']) ? array_values(array_unique(array_map('sanitize_text_field', (array) $_POST['topics']))) : [];
        $enabled = !empty($_POST['enabled']) ? 1 : 0;

        $eps = get_option(self::OPT, []);
        if (!is_array($eps)) $eps = [];
        $eps[] = [
            'url'     => $url,
            'secret'  => $secret,
            'topics'  => $topics,
            'enabled' => $enabled,
            'created' => current_time('mysql', true),
        ];
        update_option(self::OPT, $eps, false);
        wp_safe_redirect(admin_url('admin.php?page=oval15_webhooks'));
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('manage_woocommerce')) wp_die('Insufficient permissions.');
        $idx = isset($_GET['idx']) ? absint($_GET['idx']) : -1;
        check_admin_referer('oval15_webhook_delete_'.$idx);

        $eps = get_option(self::OPT, []);
        if (is_array($eps) && isset($eps[$idx])) {
            array_splice($eps, $idx, 1);
            update_option(self::OPT, $eps, false);
        }
        wp_safe_redirect(admin_url('admin.php?page=oval15_webhooks'));
        exit;
    }

    /* =========================
     * Emit + Deliver
     * ========================= */
    public static function emit($event, $data = []) {
        $eps = get_option(self::OPT, []);
        if (!is_array($eps) || empty($eps)) return;

        $idempotency = 'evt_' . wp_generate_password(12, false, false);
        foreach ($eps as $ep) {
            if (empty($ep['enabled'])) continue;
            $topics = isset($ep['topics']) ? (array) $ep['topics'] : [];
            if (!in_array($event, $topics, true)) continue;
            self::queue_delivery($ep, $event, $data, 0, $idempotency);
        }
    }

    private static function queue_delivery($ep, $event, $data, $attempt = 0, $idem = '') {
        $payload = [
            'event'      => $event,
            'id'         => $idem ?: ('evt_' . wp_generate_password(12, false, false)),
            'created_at' => gmdate('c'),
            'data'       => $data,
        ];
        $body = wp_json_encode($payload);

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('oval15_deliver_webhook', [$ep, $event, $body, (int)$attempt], self::GROUP);
        } else {
            self::deliver_now($ep, $event, $body, (int)$attempt);
        }
    }

    public static function deliver_action($ep, $event, $body, $attempt) {
        $ok = self::deliver_now($ep, $event, $body, (int)$attempt);
        if (!$ok) {
            $attempt = (int) $attempt;
            if ($attempt < count(self::$backoff)) {
                $delay = self::$backoff[$attempt];
                as_schedule_single_action(time() + $delay, 'oval15_deliver_webhook', [$ep, $event, $body, $attempt + 1], self::GROUP);
            }
        }
    }

    private static function deliver_now($ep, $event, $body, $attempt) {
        $url = isset($ep['url']) ? $ep['url'] : '';
        if (!$url) return true;

        $secret = isset($ep['secret']) ? (string) $ep['secret'] : '';
        $sig = 'sha256=' . base64_encode(hash_hmac('sha256', $body, $secret, true));

        $headers = [
            'Content-Type'       => 'application/json',
            'Accept'             => 'application/json',
            'X-Oval15-Event'     => $event,
            'X-Oval15-Signature' => $sig,
            'X-Oval15-Timestamp' => gmdate('c'),
        ];

        $resp = wp_remote_post($url, [
            'timeout' => 7,
            'blocking'=> true,
            'headers' => $headers,
            'body'    => $body,
        ]);

        if (is_wp_error($resp)) return false;
        $code = wp_remote_retrieve_response_code($resp);
        return $code >= 200 && $code < 300;
    }

    /* =========================
     * Event adapters
     * ========================= */

    public static function on_registration_completed($user_id, $order_id) {
        $user_obj = self::build_user_object($user_id);
        $order    = self::build_order_object($order_id);
        $data = [
            'user'    => $user_obj,
            'order'   => $order,
            'profile' => self::user_profile_snapshot($user_id),
        ];
        do_action('oval15/event_emit', 'registration.completed', $data);
    }

    public static function on_user_approved($user_id, $admin_id = 0) {
        $data = [
            'user'      => self::build_user_object($user_id),
            'admin_id'  => (int) $admin_id,
            'profile'   => self::user_profile_snapshot($user_id),
        ];
        do_action('oval15/event_emit', 'user.approved', $data);
    }

    public static function on_user_declined($user_id, $admin_id = 0) {
        $data = [
            'user'      => self::build_user_object($user_id),
            'admin_id'  => (int) $admin_id,
            'profile'   => self::user_profile_snapshot($user_id),
        ];
        do_action('oval15/event_emit', 'user.declined', $data);
    }

    public static function on_welcome_sent($user_id, $email) {
        $data = [
            'user' => self::build_user_object($user_id),
            'type' => 'welcome',
        ];
        do_action('oval15/event_emit', 'email.sent', $data);
    }

    public static function on_decline_sent($user_id, $email) {
        $data = [
            'user' => self::build_user_object($user_id),
            'type' => 'decline',
        ];
        do_action('oval15/event_emit', 'email.sent', $data);
    }

    public static function on_profile_updated($user_id, $changes = []) {
        $data = [
            'user'    => self::build_user_object($user_id),
            'changes' => $changes,
            'profile' => self::user_profile_snapshot($user_id),
        ];
        do_action('oval15/event_emit', 'profile.updated', $data);
    }

    public static function on_order_completed($order_id) {
        $order_obj = self::build_order_object($order_id);

        // If we can, enrich with WP user + profile snapshot
        $user_id = (int) ($order_obj['customer_id'] ?? 0);
        $data = [
            'order' => $order_obj,
        ];
        if ($user_id > 0) {
            $data['user']    = self::build_user_object($user_id, $order_id);
            $data['profile'] = self::user_profile_snapshot($user_id);
        }

        do_action('oval15/event_emit', 'order.completed', $data);
    }

    /* =========================
     * Builders
     * ========================= */

    /**
     * Build a normalized user object with account & billing details.
     */
    private static function build_user_object($user_id, $order_id = 0) {
        $user_id = (int) $user_id;
        $user    = $user_id ? get_user_by('id', $user_id) : null;

        $first = $user_id ? (string) get_user_meta($user_id, 'first_name', true) : '';
        $last  = $user_id ? (string) get_user_meta($user_id, 'last_name', true)  : '';

        $obj = [
            'id'         => $user_id,
            'email'      => $user ? (string) $user->user_email : '',
            'username'   => $user ? (string) $user->user_login : '',
            'first_name' => $first,
            'last_name'  => $last,
        ];

        // If an order is provided, include billing name/email fallback
        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $obj['billing_email']       = (string) $order->get_billing_email();
                $obj['billing_first_name']  = (string) $order->get_billing_first_name();
                $obj['billing_last_name']   = (string) $order->get_billing_last_name();
                if (!$obj['first_name'] && $obj['billing_first_name']) $obj['first_name'] = $obj['billing_first_name'];
                if (!$obj['last_name']  && $obj['billing_last_name'])  $obj['last_name']  = $obj['billing_last_name'];
                if (!$obj['email']      && $obj['billing_email'])      $obj['email']      = $obj['billing_email'];
            }
        }

        return $obj;
    }

    /**
     * Build a normalized order object with items and billing details.
     */
    private static function build_order_object($order_id) {
        if (!function_exists('wc_get_order')) return ['order_id' => (int) $order_id];

        $order = wc_get_order($order_id);
        if (!$order) return ['order_id' => (int) $order_id];

        // Items as a normal array (Zapier-friendly)
        $items = [];
        $product_ids = [];
        foreach ($order->get_items() as $item_id => $item) {
            $pid = (int) $item->get_product_id();
            if ($pid) $product_ids[] = $pid;
            $items[] = [
                'id'    => (int) $item_id,
                'name'  => $item->get_name(),
                'qty'   => (int) $item->get_quantity(),
                'total' => (float) $item->get_total(),
                'product_id' => $pid,
            ];
        }

        return [
            'order_id'   => (int) $order->get_id(),
            'status'     => (string) $order->get_status(),
            'total'      => (float)  $order->get_total(),
            'currency'   => (string) $order->get_currency(),
            'email'      => (string) $order->get_billing_email(),
            'customer_id'=> (int)    $order->get_user_id(),
            'billing_first_name' => (string) $order->get_billing_first_name(),
            'billing_last_name'  => (string) $order->get_billing_last_name(),
            'billing_phone'      => (string) $order->get_billing_phone(),
            'billing_country'    => (string) $order->get_billing_country(),
            'billing_city'       => (string) $order->get_billing_city(),
            'items'       => $items,
            'product_ids' => array_values(array_unique($product_ids)),
            // Link to registration user if set
            'registration_user' => (int) $order->get_meta('_oval15_registration_user'),
        ];
    }

    /**
     * Snapshot of important user meta for integrations.
     */
    private static function user_profile_snapshot($user_id) {
        $fields = [
            'first_name','last_name','gender','nation','current_location_country',
            'height','weight','years','months','level','league',
            'period_1','club_1','period_2','club_2','period_3','club_3','reason',
            'tournament_1','tournament_2','tournament_3',
            'main-position','secondary-position',
            'interested_country','passport',
            'v_links','v_upload_id','v_link', // legacy primary still included
            'p_profile','p_photo_id',
            '_oval15_sole_rep','_oval15_marketing_consent','_oval15_approved',
        ];
        $out = ['id' => (int) $user_id];
        foreach ($fields as $k) {
            $out[$k] = get_user_meta($user_id, $k, true);
        }
        return $out;
    }
}
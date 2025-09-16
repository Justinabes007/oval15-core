<?php
namespace Oval15\Core\Admin;

use Oval15\Core\Notifications\Emails;

if (!defined('ABSPATH')) exit;

class Approvals {
    const META_APPROVED     = '_oval15_approved';
    const META_APPROVED_AT  = '_oval15_approved_at';
    const META_APPROVED_BY  = '_oval15_approved_by';
    const META_DECLINED_AT  = '_oval15_declined_at';
    const META_DECLINED_BY  = '_oval15_declined_by';

    public static function init() {
        // Users table column
        add_filter('manage_users_columns', [__CLASS__, 'add_col']);
        add_filter('manage_users_custom_column', [__CLASS__, 'render_col'], 10, 3);

        // Row actions
        add_filter('user_row_actions', [__CLASS__, 'row_actions'], 10, 2);

        // Bulk actions
        add_filter('bulk_actions-users', [__CLASS__, 'bulk_actions']);
        add_filter('handle_bulk_actions-users', [__CLASS__, 'handle_bulk'], 10, 3);

        // Single-action endpoints
        add_action('admin_post_oval15_user_approve', [__CLASS__, 'approve_single']);
        add_action('admin_post_oval15_user_decline', [__CLASS__, 'decline_single']);
        add_action('admin_post_oval15_user_resend_welcome', [__CLASS__, 'resend_welcome']);

        // Notices
        add_action('admin_notices', [__CLASS__, 'notices']);
    }

    /* ---------- Column ---------- */

    public static function add_col($cols) {
        $cols['oval15_status'] = 'Oval15';
        return $cols;
    }

    public static function render_col($output, $column_name, $user_id) {
        if ($column_name !== 'oval15_status') return $output;
        $approved = strtolower((string) get_user_meta($user_id, self::META_APPROVED, true)) === 'yes';
        $when = $approved ? get_user_meta($user_id, self::META_APPROVED_AT, true) : get_user_meta($user_id, self::META_DECLINED_AT, true);
        $when_txt = $when ? ' · ' . esc_html(date_i18n(get_option('date_format').' '.get_option('time_format'), strtotime($when))) : '';
        if ($approved) {
            return '<span class="dashicons dashicons-yes" style="color:#28a745"></span> Approved' . $when_txt;
        }
        return '<span class="dashicons dashicons-clock" style="color:#d9831f"></span> Pending/Declined' . $when_txt;
    }

    /* ---------- Row actions ---------- */

    public static function row_actions($actions, $user) {
        if (!current_user_can('manage_woocommerce')) return $actions;

        $uid = is_object($user) ? $user->ID : (int) $user;
        $nonce = wp_create_nonce('oval15_user_action_'.$uid);

        $approve_url = admin_url('admin-post.php?action=oval15_user_approve&user_id='.$uid.'&_wpnonce='.$nonce);
        $decline_url = admin_url('admin-post.php?action=oval15_user_decline&user_id='.$uid.'&_wpnonce='.$nonce);
        $resend_url  = admin_url('admin-post.php?action=oval15_user_resend_welcome&user_id='.$uid.'&_wpnonce='.$nonce);

        $actions['oval15_approve'] = '<a href="'.esc_url($approve_url).'">Approve</a>';
        $actions['oval15_decline'] = '<a href="'.esc_url($decline_url).'">Decline</a>';
        $actions['oval15_resend']  = '<a href="'.esc_url($resend_url).'">Resend Welcome</a>';

        return $actions;
    }

    /* ---------- Bulk actions ---------- */

    public static function bulk_actions($actions) {
        if (!current_user_can('manage_woocommerce')) return $actions;
        $actions['oval15_approve'] = 'Approve (Oval15)';
        $actions['oval15_decline'] = 'Decline (Oval15)';
        return $actions;
    }

    public static function handle_bulk($redirect_to, $action, $user_ids) {
        if (!current_user_can('manage_woocommerce')) return $redirect_to;

        $ok = 0; 
        $now = current_time('mysql'); 
        $admin = get_current_user_id();

        if ($action === 'oval15_approve') {
            foreach ($user_ids as $uid) {
                $uid = (int) $uid;
                $prev = strtolower((string) get_user_meta($uid, self::META_APPROVED, true));

                update_user_meta($uid, self::META_APPROVED, 'yes');       // triggers Welcome via Emails module
                update_user_meta($uid, self::META_APPROVED_AT, $now);
                update_user_meta($uid, self::META_APPROVED_BY, $admin);
                delete_user_meta($uid, self::META_DECLINED_AT);
                delete_user_meta($uid, self::META_DECLINED_BY);

                // Emit only the relevant event, and only if status changed
                if ($prev !== 'yes') {
                    do_action('oval15/user_approved', $uid, $admin);
                }

                $ok++;
            }
            return add_query_arg(['oval15_bulk_approved' => $ok], $redirect_to);
        }

        if ($action === 'oval15_decline') {
            foreach ($user_ids as $uid) {
                $uid = (int) $uid;
                $prev = strtolower((string) get_user_meta($uid, self::META_APPROVED, true));

                update_user_meta($uid, self::META_APPROVED, 'no');
                update_user_meta($uid, self::META_DECLINED_AT, $now);
                update_user_meta($uid, self::META_DECLINED_BY, $admin);
                delete_user_meta($uid, self::META_APPROVED_AT);
                delete_user_meta($uid, self::META_APPROVED_BY);

                // Emit only the relevant event, and only if status changed
                if ($prev !== 'no') {
                    do_action('oval15/user_declined', $uid, $admin);
                }

                $ok++;
            }
            return add_query_arg(['oval15_bulk_declined' => $ok], $redirect_to);
        }

        return $redirect_to;
    }

    /* ---------- Single handlers ---------- */

    public static function approve_single() {
        if (!current_user_can('manage_woocommerce')) wp_die('Insufficient permissions.');
        $uid = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        if (!$uid || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'oval15_user_action_'.$uid)) wp_die('Invalid nonce.');

        $now = current_time('mysql'); 
        $admin = get_current_user_id();
        $prev = strtolower((string) get_user_meta($uid, self::META_APPROVED, true));

        update_user_meta($uid, self::META_APPROVED, 'yes');  // triggers Welcome via Emails module
        update_user_meta($uid, self::META_APPROVED_AT, $now);
        update_user_meta($uid, self::META_APPROVED_BY, $admin);
        delete_user_meta($uid, self::META_DECLINED_AT);
        delete_user_meta($uid, self::META_DECLINED_BY);

        // Emit BEFORE redirect; only the relevant event, and only if status changed
        if ($prev !== 'yes') {
            do_action('oval15/user_approved', $uid, $admin);
        }

        wp_safe_redirect(add_query_arg(['oval15_approved' => 1], wp_get_referer() ?: admin_url('users.php'))); 
        exit;
    }

    public static function decline_single() {
        if (!current_user_can('manage_woocommerce')) wp_die('Insufficient permissions.');
        $uid = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        if (!$uid || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'oval15_user_action_'.$uid)) wp_die('Invalid nonce.');

        $now = current_time('mysql'); 
        $admin = get_current_user_id();
        $prev = strtolower((string) get_user_meta($uid, self::META_APPROVED, true));

        update_user_meta($uid, self::META_APPROVED, 'no');
        update_user_meta($uid, self::META_DECLINED_AT, $now);
        update_user_meta($uid, self::META_DECLINED_BY, $admin);
        delete_user_meta($uid, self::META_APPROVED_AT);
        delete_user_meta($uid, self::META_APPROVED_BY);

        // Emit BEFORE redirect; only the relevant event, and only if status changed
        if ($prev !== 'no') {
            do_action('oval15/user_declined', $uid, $admin);
        }

        wp_safe_redirect(add_query_arg(['oval15_declined' => 1], wp_get_referer() ?: admin_url('users.php'))); 
        exit;
    }

    public static function resend_welcome() {
        if (!current_user_can('manage_woocommerce')) wp_die('Insufficient permissions.');
        $uid = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        if (!$uid || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'oval15_user_action_'.$uid)) wp_die('Invalid nonce.');

        $ok = method_exists(Emails::class, 'resend_to_user') ? Emails::resend_to_user($uid) : false;
        wp_safe_redirect(add_query_arg(['oval15_resend_welcome' => $ok ? '1' : '0'], wp_get_referer() ?: admin_url('users.php'))); 
        exit;
    }

    /* ---------- Notices ---------- */

    public static function notices() {
        if (isset($_GET['oval15_approved'])) echo '<div class="updated notice"><p>User approved and Welcome email queued.</p></div>';
        if (isset($_GET['oval15_declined'])) echo '<div class="updated notice"><p>User declined.</p></div>';
        if (isset($_GET['oval15_resend_welcome'])) {
            echo $_GET['oval15_resend_welcome']==='1'
                ? '<div class="updated notice"><p>Welcome email re-sent.</p></div>'
                : '<div class="error notice"><p>Failed to resend Welcome email.</p></div>';
        }
        if (isset($_GET['oval15_bulk_approved'])) {
            echo '<div class="updated notice"><p>'.intval($_GET['oval15_bulk_approved']).' users approved.</p></div>';
        }
        if (isset($_GET['oval15_bulk_declined'])) {
            echo '<div class="updated notice"><p>'.intval($_GET['oval15_bulk_declined']).' users declined.</p></div>';
        }
    }
}
<?php
namespace Oval15\Core\Diagnostics;

if (!defined('ABSPATH')) exit;

class ContractGuard {
    const REQUIRED_SHORTCODES = ['oval15_complete_registration'];
    const REQUIRED_TOPICS = [
        'registration.completed','user.approved','user.declined',
        'order.completed','email.sent','profile.updated',
    ];
    const REQUIRED_ACTIONS = [
        'oval15/registration_completed',
        'oval15/user_approved',
        'oval15/user_declined',
        'oval15/profile_updated',
    ];

    public static function init() {
        if (is_admin()) {
            add_action('admin_init', [__CLASS__, 'run_checks']);
        }
    }

    public static function run_checks() {
        $problems = [];

        // Shortcodes present
        foreach (self::REQUIRED_SHORTCODES as $sc) {
            if (!shortcode_exists($sc)) {
                $problems[] = "Missing shortcode: [{$sc}]";
            }
        }

        // Webhook topics superset
        if (class_exists('\Oval15\Core\Integrations\Webhooks') &&
            method_exists('\Oval15\Core\Integrations\Webhooks', 'topics')) {
            $topics = array_keys(\Oval15\Core\Integrations\Webhooks::topics());
            foreach (self::REQUIRED_TOPICS as $t) {
                if (!in_array($t, $topics, true)) {
                    $problems[] = "Webhook topic not exposed by Webhooks::topics(): {$t}";
                }
            }
        } else {
            $problems[] = 'Webhooks class not found or topics() missing.';
        }

        // At least one listener bound for our core actions
        foreach (self::REQUIRED_ACTIONS as $act) {
            if (!has_action($act)) {
                $problems[] = "No listeners attached to action: {$act}";
            }
        }

        if (!empty($problems) && current_user_can('manage_woocommerce')) {
            add_action('admin_notices', function() use ($problems) {
                echo '<div class="notice notice-error"><p><strong>Oval15 Contract Guard:</strong></p><ul style="margin-left:18px">';
                foreach ($problems as $p) echo '<li>'.esc_html($p).'</li>';
                echo '</ul><p>Please share this with the developer delivering the last update.</p></div>';
            });
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Oval15 Contract Guard issues: '.implode(' | ', $problems));
            }
        }
    }
}
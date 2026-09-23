<?php
/**
 * Plugin Name: Crystade Uptime Monitor
 * Description: Exposes an authenticated REST endpoint with privacy-conscious WordPress health checks.
 * Version: 0.1.0
 * Author: Alex Boles
 * License: GPL-2.0-or-later
 */

defined('ABSPATH') || exit;

final class Crystade_Uptime_Monitor {
    public const REST_NAMESPACE = 'crystade/v1';
    public const REST_ROUTE = '/status';
    private const TOKEN_CONSTANT = 'CRYSTADE_MONITOR_TOKEN';
    private const OVERDUE_GRACE_SECONDS = 300;
    private const LOW_DISK_PERCENT = 10.0;

    public static function bootstrap(): void {
        add_action('rest_api_init', [self::class, 'register_route']);
    }

    public static function register_route(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            [
                'methods' => 'GET',
                'callback' => [self::class, 'handle_status'],
                'permission_callback' => [self::class, 'authorize'],
            ]
        );
    }

    public static function authorize($request) {
        if (!defined(self::TOKEN_CONSTANT) || CRYSTADE_MONITOR_TOKEN === '') {
            return new WP_Error(
                'crystade_monitor_not_configured',
                'The monitoring token is not configured.',
                ['status' => 503]
            );
        }

        $authorization = trim((string) $request->get_header('authorization'));
        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return new WP_Error(
                'crystade_monitor_unauthorized',
                'A bearer token is required.',
                ['status' => 401]
            );
        }

        if (!hash_equals((string) CRYSTADE_MONITOR_TOKEN, trim($matches[1]))) {
            return new WP_Error(
                'crystade_monitor_forbidden',
                'The bearer token is invalid.',
                ['status' => 403]
            );
        }

        return true;
    }

    public static function handle_status($request): WP_REST_Response {
        $payload = self::build_payload();
        $http_status = $payload['status'] === 'critical' ? 503 : 200;
        $response = new WP_REST_Response($payload, $http_status);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        return $response;
    }

    public static function build_payload(): array {
        global $wpdb;

        $database_ok = self::database_is_available($wpdb);
        $maintenance_mode = file_exists(ABSPATH . '.maintenance');
        $overdue_cron_jobs = self::count_overdue_cron_jobs();
        $disk_free_percent = self::disk_free_percent(ABSPATH);
        $updates = self::available_update_counts();

        $critical = !$database_ok || $maintenance_mode;
        $degraded = $overdue_cron_jobs > 0
            || ($disk_free_percent !== null && $disk_free_percent < self::LOW_DISK_PERCENT)
            || $updates['plugins'] > 0
            || $updates['themes'] > 0
            || $updates['core'];

        return [
            'status' => $critical ? 'critical' : ($degraded ? 'degraded' : 'ok'),
            'checked_at_utc' => gmdate('c'),
            'site' => self::site_host(),
            'checks' => [
                'database' => ['ok' => $database_ok],
                'maintenance_mode' => ['active' => $maintenance_mode],
                'cron' => ['overdue_jobs' => $overdue_cron_jobs],
                'disk' => ['free_percent' => $disk_free_percent],
                'updates' => $updates,
            ],
        ];
    }

    private static function database_is_available($wpdb): bool {
        if (!is_object($wpdb) || !method_exists($wpdb, 'check_connection')) {
            return false;
        }

        return (bool) $wpdb->check_connection(false);
    }

    private static function count_overdue_cron_jobs(): int {
        $cron = function_exists('_get_cron_array') ? _get_cron_array() : [];
        if (!is_array($cron)) {
            return 0;
        }

        $cutoff = time() - self::OVERDUE_GRACE_SECONDS;
        $count = 0;
        foreach ($cron as $timestamp => $hooks) {
            if ((int) $timestamp >= $cutoff || !is_array($hooks)) {
                continue;
            }
            foreach ($hooks as $events) {
                if (is_array($events)) {
                    $count += count($events);
                }
            }
        }
        return $count;
    }

    private static function disk_free_percent(string $path): ?float {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total === false || $free === false || $total <= 0) {
            return null;
        }
        return round(($free / $total) * 100, 2);
    }

    private static function available_update_counts(): array {
        $plugin_updates = function_exists('get_site_transient')
            ? get_site_transient('update_plugins')
            : null;
        $theme_updates = function_exists('get_site_transient')
            ? get_site_transient('update_themes')
            : null;
        $core_updates = function_exists('get_site_transient')
            ? get_site_transient('update_core')
            : null;

        $core_available = false;
        if (is_object($core_updates) && isset($core_updates->updates) && is_array($core_updates->updates)) {
            foreach ($core_updates->updates as $update) {
                if (is_object($update) && isset($update->response) && $update->response === 'upgrade') {
                    $core_available = true;
                    break;
                }
            }
        }

        return [
            'core' => $core_available,
            'plugins' => is_object($plugin_updates) && isset($plugin_updates->response)
                ? count((array) $plugin_updates->response)
                : 0,
            'themes' => is_object($theme_updates) && isset($theme_updates->response)
                ? count((array) $theme_updates->response)
                : 0,
        ];
    }

    private static function site_host(): string {
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }
}

Crystade_Uptime_Monitor::bootstrap();

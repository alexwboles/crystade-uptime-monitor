<?php

declare(strict_types=1);

$test_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crystade-monitor-test' . DIRECTORY_SEPARATOR;
if (!is_dir($test_root)) {
    mkdir($test_root, 0777, true);
}

define('ABSPATH', $test_root);
define('CRYSTADE_MONITOR_TOKEN', 'test-token-123');

$registered_route = null;
$cron_fixture = [];
$transient_fixture = [];

function add_action($hook, $callback): void {}
function register_rest_route($namespace, $route, $args): void {
    global $registered_route;
    $registered_route = compact('namespace', 'route', 'args');
}
function home_url($path = ''): string { return 'https://example.test' . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function _get_cron_array(): array { global $cron_fixture; return $cron_fixture; }
function get_site_transient($name) { global $transient_fixture; return $transient_fixture[$name] ?? null; }

final class WP_Error {
    public function __construct(public string $code, public string $message, public array $data = []) {}
}

final class WP_REST_Request {
    public function __construct(private array $headers = []) {}
    public function get_header(string $name): string { return $this->headers[strtolower($name)] ?? ''; }
}

final class WP_REST_Response {
    public array $headers = [];
    public function __construct(public array $data, public int $status = 200) {}
    public function header(string $name, string $value): void { $this->headers[$name] = $value; }
}

final class FakeWpdb {
    public function __construct(private bool $connected) {}
    public function check_connection($allow_bail = true): bool { return $this->connected; }
}

function assert_true($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require dirname(__DIR__) . '/crystade-uptime-monitor.php';

Crystade_Uptime_Monitor::register_route();
assert_true($registered_route['namespace'] === 'crystade/v1', 'REST namespace is registered.');
assert_true($registered_route['route'] === '/status', 'REST route is registered.');
assert_true($registered_route['args']['methods'] === 'GET', 'REST route is GET-only.');

$missing = Crystade_Uptime_Monitor::authorize(new WP_REST_Request());
assert_true($missing instanceof WP_Error && $missing->data['status'] === 401, 'Missing token is rejected.');

$wrong = Crystade_Uptime_Monitor::authorize(new WP_REST_Request(['authorization' => 'Bearer wrong']));
assert_true($wrong instanceof WP_Error && $wrong->data['status'] === 403, 'Wrong token is rejected.');

$valid = Crystade_Uptime_Monitor::authorize(new WP_REST_Request(['authorization' => 'Bearer test-token-123']));
assert_true($valid === true, 'Valid token is accepted.');

$wpdb = new FakeWpdb(true);
$response = Crystade_Uptime_Monitor::handle_status(new WP_REST_Request());
assert_true($response->status === 200, 'Healthy endpoint returns HTTP 200.');
assert_true($response->data['status'] === 'ok', 'Healthy endpoint reports ok.');
assert_true($response->data['site'] === 'example.test', 'Only the site host is exposed.');
assert_true(!isset($response->data['wordpress_version']), 'WordPress version is not exposed.');
assert_true($response->headers['Cache-Control'] === 'no-store, no-cache, must-revalidate', 'Response disables caching.');

$wpdb = new FakeWpdb(false);
$response = Crystade_Uptime_Monitor::handle_status(new WP_REST_Request());
assert_true($response->status === 503, 'Database failure returns HTTP 503.');
assert_true($response->data['status'] === 'critical', 'Database failure reports critical.');

$wpdb = new FakeWpdb(true);
$cron_fixture = [
    time() - 900 => ['scheduled_task' => ['abc' => ['schedule' => false]]],
];
$response = Crystade_Uptime_Monitor::handle_status(new WP_REST_Request());
assert_true($response->status === 200, 'Degraded state stays parseable over HTTP 200.');
assert_true($response->data['status'] === 'degraded', 'Overdue cron reports degraded.');
assert_true($response->data['checks']['cron']['overdue_jobs'] === 1, 'Overdue cron count is reported.');

echo "PASS: 13 assertions\n";

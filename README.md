# Crystade Uptime Monitor

A small WordPress plugin proof for an external uptime-monitoring integration.
It exposes `GET /wp-json/crystade/v1/status` and returns a compact JSON health
report without publishing WordPress, PHP, plugin, or theme version numbers.

## Checks returned

- Database connectivity (critical if unavailable)
- WordPress maintenance mode (critical when active)
- Overdue cron-event count (degraded when non-zero)
- Free-disk percentage (degraded below 10%)
- Counts of available core, plugin, and theme updates

The endpoint returns HTTP 503 for critical failures and HTTP 200 for healthy or
degraded states. The JSON `status` field lets Crystade distinguish `ok`,
`degraded`, and `critical` results.

## Installation

1. Copy this directory into `wp-content/plugins/`.
2. Add a long random token to `wp-config.php`:

   ```php
   define('CRYSTADE_MONITOR_TOKEN', 'replace-with-a-long-random-secret');
   ```

3. Activate **Crystade Uptime Monitor** in WordPress.
4. Configure Crystade to call:

   ```text
   https://example.com/wp-json/crystade/v1/status
   ```

   with the request header:

   ```text
   Authorization: Bearer replace-with-a-long-random-secret
   ```

The route fails closed with HTTP 503 if no token is configured. Responses use
`Cache-Control: no-store` so monitoring data is not cached by intermediaries.

## Verification

The plugin is linted and its route, authentication, privacy, health-state, and
HTTP-status behavior are exercised in a self-contained PHP test harness:

```powershell
php -l crystade-uptime-monitor.php
php tests/test-plugin.php
```

The current verification run used PHP 8.5.10 on Windows and passed syntax
validation plus all 13 behavioral assertions.

The exact Crystade authentication and response schema should be confirmed with
the client before final integration. This proof intentionally keeps the
transport adapter small so the payload can be mapped to their documented
schema without rewriting the WordPress health checks.

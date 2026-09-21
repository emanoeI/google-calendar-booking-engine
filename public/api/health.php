<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

booking_method('GET');
booking_rate_limit('health', 10);

$config = booking_config();
$timezone = new DateTimeZone($config['timezone']);
$now = new DateTimeImmutable('now', $timezone);

try {
    booking_gateway()->busy($now, $now->modify('+1 minute'));

    booking_json([
        'status'    => 'ok',
        'timestamp' => $now->format(DATE_RFC3339),
    ]);
} catch (Throwable $error) {
    error_log('Health check degraded: ' . $error->getMessage());

    booking_json([
        'status' => 'degraded',
    ], 503);
}

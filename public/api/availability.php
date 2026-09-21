<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

booking_method('GET');
booking_rate_limit('availability', 60);

$config = booking_config();
$timezone = new DateTimeZone($config['timezone']);

$service = booking_service((string)($_GET['serviceId'] ?? ''));
$from = booking_date((string)($_GET['from'] ?? ''), $timezone);
$to = booking_date((string)($_GET['to'] ?? ''), $timezone);

if (!$service) {
    booking_error('INVALID_SERVICE', 'Serviço informado não encontrado ou inativo.', 400, ['serviceId']);
}

if (!$from || !$to || $to <= $from || $from->diff($to)->days > 14) {
    booking_error('INVALID_WINDOW', 'Janela de consulta inválida. Intervalo máximo permitido: 14 dias.', 400, ['from', 'to']);
}

$now = new DateTimeImmutable('now', $timezone);
$earliest = $now->modify('+' . $config['minimumNoticeMinutes'] . ' minutes');
$latest = $now->modify('+' . $config['bookingHorizonDays'] . ' days')->setTime(23, 59);

$windowFrom = $from < $earliest->setTime(0, 0) ? $earliest->setTime(0, 0) : $from;
$windowTo = $to > $latest->setTime(0, 0) ? $latest : $to->setTime(23, 59);

try {
    $busy = booking_gateway()->busy($windowFrom, $windowTo);
} catch (Throwable $error) {
    error_log('Falha na consulta de freeBusy do calendário: ' . $error->getMessage());
    booking_calendar_error($error);
}

$days = [];

for ($day = $from; $day < $to; $day = $day->modify('+1 day')) {
    $weekday = (int)$day->format('N');
    foreach ($config['openingHours'][$weekday] ?? [] as [$open, $close]) {
        [$openHour, $openMinute] = array_map('intval', explode(':', $open));
        [$closeHour, $closeMinute] = array_map('intval', explode(':', $close));
        $slot = $day->setTime($openHour, $openMinute);
        $closing = $day->setTime($closeHour, $closeMinute);

        while ($slot < $closing) {
            $end = $slot->modify('+' . $service['durationMinutes'] . ' minutes');
            $blockedStart = $slot->modify('-' . $config['bookingGapMinutes'] . ' minutes');
            $blockedEnd = $end->modify('+' . $config['bookingGapMinutes'] . ' minutes');

            if (
                $slot >= $earliest &&
                $end <= $closing &&
                $end <= $latest &&
                !booking_overlaps($blockedStart, $blockedEnd, $busy)
            ) {
                $days[$day->format('Y-m-d')][] = [
                    'start' => $slot->format(DATE_RFC3339),
                    'end'   => $end->format(DATE_RFC3339),
                ];
            }

            $slot = $slot->modify('+' . $config['slotIntervalMinutes'] . ' minutes');
        }
    }
}

booking_json([
    'timezone' => $config['timezone'],
    'days'     => $days,
]);

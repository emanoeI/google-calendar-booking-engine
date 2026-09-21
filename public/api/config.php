<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

booking_method('GET');

$config = booking_config();

booking_json([
    'timezone'             => $config['timezone'],
    'whatsapp'             => $config['whatsapp'],
    'minimumNoticeMinutes' => $config['minimumNoticeMinutes'],
    'bookingHorizonDays'   => $config['bookingHorizonDays'],
    'bookingGapMinutes'    => $config['bookingGapMinutes'],
    'maxAddOns'            => $config['maxAddOns'] ?? 3,
    'services'             => $config['services'],
    'addOns'               => $config['addOns'] ?? [],
]);

<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

booking_method('POST');
booking_check_origin();
booking_rate_limit('book', 5);

$config = booking_config();
$rawBody = file_get_contents('php://input');

if (strlen($rawBody) > 65536) {
    booking_error('PAYLOAD_TOO_LARGE', 'O payload da requisição excede o limite suportado (64KB).', 400);
}

$body = json_decode($rawBody, true);

// Validação de Honeypot contra automações não autorizadas
if (!is_array($body) || ($body['website'] ?? '') !== '') {
    booking_error('INVALID_REQUEST', 'Falha na validação do payload.', 400);
}

booking_check_turnstile($body);

// Verificação de campos obrigatórios
$required = ['idempotencyKey', 'serviceId', 'start', 'customerName', 'whatsapp'];
foreach ($required as $field) {
    if (!array_key_exists($field, $body) || trim((string)$body[$field]) === '') {
        booking_error('MISSING_REQUIRED_FIELD', "Campo obrigatório ausente: {$field}.", 400, [$field]);
    }
}

$service = booking_service((string)$body['serviceId']);
if (!$service) {
    booking_error('INVALID_SERVICE', 'Serviço informado não cadastrado ou inativo.', 400, ['serviceId']);
}

$addons = is_array($body['addOns'] ?? null) ? $body['addOns'] : [];
$validAddons = array_column($config['addOns'] ?? [], 'id');

if (count($addons) !== count(array_unique($addons, SORT_STRING))) {
    booking_error('DUPLICATE_ADDONS', 'Adicionais duplicados detectados.', 400, ['addOns']);
}

if (array_diff($addons, $validAddons)) {
    booking_error('INVALID_ADDONS', 'Um ou mais adicionais informados são inválidos.', 400, ['addOns']);
}

$maxAddons = $config['maxAddOns'] ?? 3;
if (count($addons) > $maxAddons) {
    booking_error('ADDON_LIMIT_EXCEEDED', "Limite de adicionais excedido (máximo: {$maxAddons}).", 400, ['addOns']);
}

$timezone = new DateTimeZone($config['timezone']);
$start = DateTimeImmutable::createFromFormat(DATE_RFC3339, (string)$body['start']);
if (!$start) {
    booking_error('INVALID_DATETIME', 'Formato de data/hora inválido (RFC3339 obrigatório).', 400, ['start']);
}

$start = $start->setTimezone($timezone);
$end = $start->modify('+' . $service['durationMinutes'] . ' minutes');
$now = new DateTimeImmutable('now', $timezone);

if ($start < $now->modify('+' . $config['minimumNoticeMinutes'] . ' minutes')) {
    booking_error('MINIMUM_NOTICE_VIOLATION', 'Antecedência mínima de agendamento não atendida.', 409);
}

if ($start > $now->modify('+' . $config['bookingHorizonDays'] . ' days')) {
    booking_error('BOOKING_HORIZON_EXCEEDED', 'Data fora do horizonte de agendamento permitido.', 409);
}

$normalized = [
    'serviceId'    => $service['id'],
    'addOns'       => array_values($addons),
    'start'        => $start->format(DATE_RFC3339),
    'customerName' => trim((string)$body['customerName']),
    'whatsapp'     => trim((string)$body['whatsapp']),
    'notes'        => trim((string)($body['notes'] ?? '')),
];

if (!booking_human_name($normalized['customerName'])) {
    booking_error('INVALID_CUSTOMER_NAME', 'Nome de cliente inválido.', 400, ['customerName']);
}

$whatsappDigits = preg_replace('/\D+/', '', $normalized['whatsapp']);
if (is_string($whatsappDigits) && str_starts_with($whatsappDigits, '55') && strlen($whatsappDigits) > 11) {
    $whatsappDigits = substr($whatsappDigits, 2);
}
if (!is_string($whatsappDigits) || !in_array(strlen($whatsappDigits), [10, 11], true) || !preg_match('/^[1-9][0-9][2-9][0-9]{7,8}$/', $whatsappDigits) || preg_match('/^(\d)\1+$/', $whatsappDigits) === 1) {
    booking_error('INVALID_PHONE_NUMBER', 'Número de WhatsApp inválido (DDD + 8 ou 9 dígitos).', 400, ['whatsapp']);
}

$key = (string)$body['idempotencyKey'];
if (!preg_match('/^[0-9a-f-]{20,80}$/i', $key)) {
    booking_error('INVALID_IDEMPOTENCY_KEY', 'Chave de idempotência em formato inválido.', 400, ['idempotencyKey']);
}

$payloadHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$eventId = substr(hash('sha256', $key), 0, 40);
$reference = 'BK-' . strtoupper(substr($eventId, 0, 8));

// Exclusão mútua: evita race conditions e double-booking no mesmo slot
$lockPath = getenv('BOOKING_LOCK_PATH') ?: sys_get_temp_dir() . '/booking-engine.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    booking_error('CONCURRENT_LOCK_BUSY', 'Recurso em lock concorrente. Repita a operação.', 429);
}

try {
    $selectedAddons = array_values(array_filter(
        $config['addOns'] ?? [],
        static fn (array $addon): bool => in_array($addon['id'], $addons, true)
    ));
    $price = $service['priceFromCents'] + array_sum(array_column($selectedAddons, 'priceIncrementCents'));
    $presentation = $config['calendarPresentation']['services'][$service['id']] ?? ['colorId' => '8', 'icon' => '📅'];

    $privateProperties = [
        'source'         => 'booking-engine-api',
        'serviceId'      => $service['id'],
        'addOns'         => implode(',', $addons),
        'bookingKeyHash' => hash('sha256', $key),
        'payloadHash'    => $payloadHash,
        'schemaVersion'  => '1',
    ];

    $gateway = booking_gateway();
    $replayed = false;

    // Verificação de idempotência via metadados do evento
    $existing = $gateway->get($eventId);

    if ($existing) {
        $existingProperties = $existing['privateProperties'] ?? [];
        if (($existingProperties['bookingKeyHash'] ?? '') !== $privateProperties['bookingKeyHash'] ||
            ($existingProperties['payloadHash'] ?? '') !== $payloadHash) {
            booking_error('IDEMPOTENCY_PAYLOAD_CONFLICT', 'Chave de idempotência já utilizada para payload divergente.', 409);
        }
        $replayed = true;
    } else {
        // Revalidação determinística de colisão de slot sob lock
        $busyStart = $start->modify('-' . $config['bookingGapMinutes'] . ' minutes');
        $busyEnd = $end->modify('+' . $config['bookingGapMinutes'] . ' minutes');

        if (booking_overlaps($busyStart, $busyEnd, $gateway->busy($busyStart, $busyEnd))) {
            booking_error('SLOT_UNAVAILABLE', 'Horário indisponível ou já reservado.', 409);
        }

        $addonLines = $selectedAddons
            ? implode("\n", array_map(static fn (array $addon): string => '• ' . $addon['name'], $selectedAddons))
            : '• Nenhum adicional';

        $description = "{$presentation['icon']} AGENDAMENTO CONFIRMADO\n"
            . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
            . "CLIENTE\n"
            . "Nome: {$normalized['customerName']}\n"
            . "Contato: " . booking_whatsapp_label($normalized['whatsapp']) . "\n\n"
            . "SERVIÇO\n"
            . "Item: {$service['name']}\n"
            . "Duração: {$service['durationMinutes']} min\n"
            . "Adicionais:\n{$addonLines}\n"
            . "Valor: R$ " . number_format($price / 100, 2, ',', '.') . "\n\n"
            . "OBSERVAÇÕES\n"
            . ($normalized['notes'] !== '' ? $normalized['notes'] : 'Nenhuma observação.') . "\n\n"
            . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
            . "Referência: {$reference}\n"
            . "ID Operacional: {$eventId}";

        try {
            $gateway->insert($eventId, [
                'summary'           => "{$presentation['icon']} {$service['name']} · {$normalized['customerName']}",
                'description'       => $description,
                'colorId'           => $presentation['colorId'],
                'start'             => $start->format(DATE_RFC3339),
                'end'               => $end->format(DATE_RFC3339),
                'timezone'          => $config['timezone'],
                'privateProperties' => $privateProperties,
            ]);
        } catch (Throwable $insertError) {
            $existing = $gateway->get($eventId);
            $existingProperties = $existing['privateProperties'] ?? [];
            if (!$existing || ($existingProperties['payloadHash'] ?? '') !== $payloadHash) {
                throw $insertError;
            }
            $replayed = true;
        }
    }

    $whatsappMessage = "Agendamento confirmado:\n\n"
        . "Nome: {$normalized['customerName']}\n"
        . "Serviço: {$service['name']}\n"
        . "Data: " . $start->format('d/m/Y') . "\n"
        . "Horário: " . $start->format('H:i') . "\n"
        . "Referência: {$reference}";

    booking_json([
        'status'   => 'confirmed',
        'replayed' => $replayed,
        'booking'  => [
            'reference'      => $reference,
            'serviceId'      => $service['id'],
            'serviceName'    => $service['name'],
            'addOns'         => $selectedAddons,
            'start'          => $start->format(DATE_RFC3339),
            'end'            => $end->format(DATE_RFC3339),
            'customerName'   => $normalized['customerName'],
            'notes'          => $normalized['notes'],
            'estimatedPrice' => [
                'currency'  => 'BRL',
                'fromCents' => $price,
            ],
        ],
        'whatsapp' => [
            'message' => $whatsappMessage,
            'url'     => 'https://wa.me/' . $config['whatsapp'] . '?text=' . rawurlencode($whatsappMessage),
        ],
    ]);
} catch (Throwable $error) {
    error_log('Falha na confirmação de agendamento: ' . $error->getMessage());
    booking_calendar_error($error);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

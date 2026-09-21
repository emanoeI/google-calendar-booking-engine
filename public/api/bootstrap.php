<?php

declare(strict_types=1);

use BookingEngine\CalendarGateway;
use BookingEngine\GoogleCalendarGateway;
use BookingEngine\RateLimiter;
use Google\Service\Exception as GoogleServiceException;

if (file_exists(dirname(__DIR__, 2) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
}
require_once dirname(__DIR__, 2) . '/src/autoload.php';

/**
 * Retorna as configurações da aplicação com cache local estático.
 */
function booking_config(): array
{
    static $config;
    return $config ??= require dirname(__DIR__, 2) . '/config/booking.php';
}

/**
 * Emite resposta JSON padronizada e encerra o ciclo de vida da requisição.
 */
function booking_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Emite payload de erro em formato RFC 7807 simplificado.
 */
function booking_error(string $code, string $message, int $status, array $fields = []): never
{
    booking_json([
        'error' => [
            'code'    => $code,
            'message' => $message,
            'fields'  => $fields,
        ],
    ], $status);
}

/**
 * Inicializa a instância do CalendarGateway a partir das variáveis de ambiente.
 */
function booking_gateway(): CalendarGateway
{
    $credentials = getenv('GOOGLE_CREDENTIALS_PATH') ?: '';
    $calendarId = getenv('GOOGLE_CALENDAR_ID') ?: '';

    try {
        return new GoogleCalendarGateway($credentials, $calendarId);
    } catch (Throwable $error) {
        error_log('Falha na inicialização do GoogleCalendarGateway: ' . $error->getMessage());
        throw new RuntimeException('Gateway de calendário indisponível.', 0, $error);
    }
}

/**
 * Validação de cabeçalho de origem (CORS).
 */
function booking_check_origin(): void
{
    $expected = trim((string)(getenv('APP_ORIGIN') ?: ''));
    if ($expected === '') {
        return;
    }

    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== $expected) {
        booking_error('ORIGIN_REJECTED', 'Origem não autorizada.', 403);
    }
}

/**
 * Validação de token de desafio Cloudflare Turnstile.
 */
function booking_check_turnstile(array $body): void
{
    if ((getenv('APP_ENV') ?: 'development') !== 'production') {
        return;
    }

    $token = (string)($body['turnstileToken'] ?? '');
    $secret = (string)(getenv('TURNSTILE_SECRET_KEY') ?: '');

    if ($token === '' || $secret === '') {
        booking_error('TURNSTILE_INVALID', 'Falha na validação do token anti-bot.', 422);
    }

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query([
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]),
        ],
    ]);

    $response = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
    $decoded = is_string($response) ? json_decode($response, true) : null;

    if (!is_array($decoded) || ($decoded['success'] ?? false) !== true) {
        booking_error('TURNSTILE_INVALID', 'Token anti-bot inválido ou expirado.', 422);
    }
}

/**
 * Mapeamento e normalização de exceções da Google API para códigos HTTP correspondentes.
 */
function booking_calendar_error(Throwable $error): never
{
    $status = $error instanceof GoogleServiceException ? $error->getCode() : 0;

    if ($status === 401 || $status === 403) {
        booking_error('CALENDAR_AUTH_ERROR', 'Falha de autenticação junto ao provedor de calendário.', 503);
    }

    booking_error('CALENDAR_UNAVAILABLE', 'Serviço de calendário temporariamente indisponível.', 503);
}

/**
 * Validação de método HTTP restrito.
 */
function booking_method(string $expected): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $expected) {
        booking_error('METHOD_NOT_ALLOWED', "Método {$_SERVER['REQUEST_METHOD']} não permitido.", 405);
    }
}

/**
 * Aplicação de rate limiting deslizante por endereço IP.
 */
function booking_rate_limit(string $bucket, int $limit): void
{
    $identity = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $directory = getenv('BOOKING_RATE_LIMIT_PATH') ?: sys_get_temp_dir() . '/booking-rate-limits';

    if (!(new RateLimiter($directory))->allow($bucket, $identity, $limit)) {
        header('Retry-After: 60');
        booking_error('RATE_LIMITED', 'Limite de requisições excedido. Tente novamente em instantes.', 429);
    }
}

/**
 * Parser de data com fuso horário e validação de consistência estrita.
 */
function booking_date(string $value, DateTimeZone $timezone): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
}

/**
 * Localização de serviço no catálogo por ID.
 */
function booking_service(string $id): ?array
{
    foreach (booking_config()['services'] as $service) {
        if ($service['id'] === $id) {
            return $service;
        }
    }
    return null;
}

/**
 * Formatação canônica de número de telefone (E.164 ou nacional).
 */
function booking_whatsapp_label(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value);
    if (!is_string($digits)) return $value;

    if (strlen($digits) === 13 && str_starts_with($digits, '55')) {
        return '+55 (' . substr($digits, 2, 2) . ') ' . substr($digits, 4, 5) . '-' . substr($digits, 9);
    }
    if (strlen($digits) === 11) {
        return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 5) . '-' . substr($digits, 7);
    }
    return $value;
}

function booking_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

/**
 * Validação de integridade de nome humano (mínimo de caracteres e restrição a caracteres de texto/pontuação).
 */
function booking_human_name(string $value, int $minimum = 2): bool
{
    return booking_text_length($value) >= $minimum
        && preg_match('/\p{L}/u', $value) === 1
        && preg_match('/^[\p{L}\s\'’.-]+$/u', $value) === 1;
}

/**
 * Algoritmo de intersecção temporal entre janelas de agendamento e períodos ocupados.
 */
function booking_overlaps(DateTimeImmutable $start, DateTimeImmutable $end, array $busy): bool
{
    foreach ($busy as $period) {
        if ($start < $period['end'] && $end > $period['start']) {
            return true;
        }
    }
    return false;
}

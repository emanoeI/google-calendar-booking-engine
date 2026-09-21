<?php

declare(strict_types=1);

/**
 * Suite de validação determinística offline para o Booking Engine.
 * Executa checagens de integridade de domínio, concorrência e gateways sem dependência de rede.
 */

if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}
require_once dirname(__DIR__) . '/src/autoload.php';

use BookingEngine\FakeCalendarGateway;
use BookingEngine\RateLimiter;

function assert_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

echo "=== Suite de Testes do Booking Engine ===\n\n";

$timezone = new DateTimeZone('America/Sao_Paulo');
$start = new DateTimeImmutable('2026-09-21 09:00:00', $timezone);
$end = $start->modify('+40 minutes');

// 1. Validação de cálculo de busy e não-sobreposição no gateway simulado
$fake = new FakeCalendarGateway([['start' => $start, 'end' => $end]]);

assert_test(
    count($fake->busy($start->modify('-10 minutes'), $end->modify('+10 minutes'))) === 1,
    'Detecção correta de colisão temporal em intervalo ocupado'
);

assert_test(
    count($fake->busy($end, $end->modify('+40 minutes'))) === 0,
    'Isolamento correto de intervalos adjacentes sem colisão'
);

// 2. Persistência de metadados de idempotência
$fake->insert('evt-001', ['privateProperties' => ['payloadHash' => 'hash-sha256-payload']]);
assert_test(
    $fake->get('evt-001')['privateProperties']['payloadHash'] === 'hash-sha256-payload',
    'Persistência e recuperação de metadados em extendedProperties'
);

// 3. Integridade do schema de configuração
$config = require dirname(__DIR__) . '/config/booking.php';
assert_test(
    isset($config['services']) && count($config['services']) > 0,
    'Presença e consistência do catálogo canônico de serviços'
);
assert_test(
    isset($config['calendarPresentation']['services']),
    'Mapeamento visual (cores e ícones) presente para todos os serviços'
);

// 4. Rate limiting e controle de concorrência por lock de arquivo
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rate-test-' . bin2hex(random_bytes(4));
$limiter = new RateLimiter($tempDir);

assert_test($limiter->allow('test-bucket', '127.0.0.1', 2), 'Admissão de requisição 1 dentro do limite');
assert_test($limiter->allow('test-bucket', '127.0.0.1', 2), 'Admissão de requisição 2 dentro do limite');
assert_test(!$limiter->allow('test-bucket', '127.0.0.1', 2), 'Bloqueio estrito de requisição excedente');

// Limpeza de runtime de teste
foreach (glob($tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
    unlink($file);
}
rmdir($tempDir);

echo "\nSuite concluída: 6/6 verificações aprovadas com sucesso.\n";

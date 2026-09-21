<?php

declare(strict_types=1);

/**
 * Autoloader PSR-4 autônomo para o namespace BookingEngine.
 * Permite execução da suite de testes e classes do domínio sem dependência estrita do Composer.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'BookingEngine\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

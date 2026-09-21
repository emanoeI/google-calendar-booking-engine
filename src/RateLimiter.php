<?php

declare(strict_types=1);

namespace BookingEngine;

/**
 * Rate Limiter atômico baseado em sistema de arquivos com janela deslizante.
 * Emprega flock(LOCK_EX) para serialização atômica de leituras e escritas sem dependência de Redis.
 */
final class RateLimiter
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
    }

    /**
     * Valida se o identificador excedeu o limite de requisições na janela informada.
     *
     * @param string $bucket Escopo do limite (ex: 'book', 'availability')
     * @param string $identity Identificador do cliente (IP ou token)
     * @param int $limit Número máximo de requisições autorizadas
     * @param int $windowSeconds Tamanho da janela temporal em segundos
     * @return bool True se admitido, False se bloqueado
     */
    public function allow(string $bucket, string $identity, int $limit, int $windowSeconds = 60): bool
    {
        $filename = hash('sha256', $bucket . '|' . $identity) . '.json';
        $path = $this->directory . DIRECTORY_SEPARATOR . $filename;

        $handle = @fopen($path, 'c+');
        if (!$handle || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return false;
        }

        try {
            rewind($handle);
            $content = stream_get_contents($handle) ?: '[]';
            $timestamps = json_decode($content, true);
            $timestamps = is_array($timestamps) ? array_values(array_filter($timestamps, 'is_int')) : [];

            $now = time();
            $timestamps = array_values(array_filter(
                $timestamps,
                static fn (int $timestamp): bool => $timestamp > $now - $windowSeconds
            ));

            if (count($timestamps) >= $limit) {
                return false;
            }

            $timestamps[] = $now;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($timestamps, JSON_THROW_ON_ERROR));
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

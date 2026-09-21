<?php

declare(strict_types=1);

namespace BookingEngine;

use DateTimeImmutable;

/**
 * Contrato de abstração para operações de leitura e mutação na agenda.
 * Isola o domínio da aplicação da camada de transporte externa (Google API).
 */
interface CalendarGateway
{
    /**
     * Recupera intervalos ocupados dentro da janela delimitada.
     *
     * @return list<array{start: DateTimeImmutable, end: DateTimeImmutable}>
     */
    public function busy(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Persiste um novo registro de agendamento.
     *
     * @param array<string, mixed> $event Estrutura normalizada do evento
     * @return array{id: string} Identificador único atribuído
     */
    public function insert(string $eventId, array $event): array;

    /**
     * Consulta um evento por identificador determinístico para validação de idempotência.
     *
     * @return array<string, mixed>|null Metadados e propriedades privadas do evento, ou null se inexistente
     */
    public function get(string $eventId): ?array;
}

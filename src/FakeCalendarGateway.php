<?php

declare(strict_types=1);

namespace BookingEngine;

use DateTimeImmutable;
use RuntimeException;

/**
 * Dublê de teste in-memory para validação determinística de agendamentos.
 * Permite execução de testes automatizados e suítes de regressão sem dependência de rede.
 */
final class FakeCalendarGateway implements CalendarGateway
{
    /** @var list<array{start: DateTimeImmutable, end: DateTimeImmutable}> */
    private array $busyPeriods = [];

    /** @var array<string, array<string, mixed>> */
    private array $events = [];

    /**
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable}> $busyPeriods Períodos preexistentes
     */
    public function __construct(array $busyPeriods = [])
    {
        $this->busyPeriods = $busyPeriods;
    }

    public function busy(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return array_values(array_filter(
            $this->busyPeriods,
            static fn (array $period): bool => $period['start'] < $to && $period['end'] > $from
        ));
    }

    public function insert(string $eventId, array $event): array
    {
        if (isset($this->events[$eventId])) {
            throw new RuntimeException("Conflito de chave primária: evento {$eventId} já existente.");
        }

        $this->events[$eventId] = $event;
        return ['id' => $eventId];
    }

    public function get(string $eventId): ?array
    {
        if (!isset($this->events[$eventId])) {
            return null;
        }

        return [
            'id' => $eventId,
            'privateProperties' => $this->events[$eventId]['privateProperties'] ?? [],
        ];
    }
}

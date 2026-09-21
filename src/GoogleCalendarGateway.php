<?php

declare(strict_types=1);

namespace BookingEngine;

use DateTimeImmutable;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Calendar\FreeBusyRequestItem;
use Google\Service\Exception as GoogleServiceException;
use RuntimeException;

/**
 * Adaptador de infraestrutura para a Google Calendar API v3.
 * Opera via Service Account com escopos restritos de eventos e freeBusy.
 */
final class GoogleCalendarGateway implements CalendarGateway
{
    private Calendar $calendar;
    private string $calendarId;

    public function __construct(string $credentialsPath, string $calendarId)
    {
        if (!is_file($credentialsPath)) {
            throw new RuntimeException("Arquivo de credenciais inexistente: {$credentialsPath}");
        }

        if (trim($calendarId) === '') {
            throw new RuntimeException('GOOGLE_CALENDAR_ID não configurado.');
        }

        $client = new Client();
        $client->setAuthConfig($credentialsPath);
        $client->setScopes([
            Calendar::CALENDAR_EVENTS,
            Calendar::CALENDAR_EVENTS_FREEBUSY,
        ]);

        $this->calendar = new Calendar($client);
        $this->calendarId = $calendarId;
    }

    public function busy(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $request = new FreeBusyRequest();
        $request->setTimeMin($from->format(DATE_RFC3339));
        $request->setTimeMax($to->format(DATE_RFC3339));
        $request->setItems([new FreeBusyRequestItem(['id' => $this->calendarId])]);

        $response = $this->calendar->freebusy->query($request);
        $periods = $response->getCalendars()[$this->calendarId]->getBusy() ?? [];

        return array_map(static fn ($period): array => [
            'start' => new DateTimeImmutable($period->getStart()),
            'end' => new DateTimeImmutable($period->getEnd()),
        ], $periods);
    }

    public function insert(string $eventId, array $event): array
    {
        $start = new EventDateTime();
        $start->setDateTime($event['start']);
        $start->setTimeZone($event['timezone']);

        $end = new EventDateTime();
        $end->setDateTime($event['end']);
        $end->setTimeZone($event['timezone']);

        $googleEvent = new Event([
            'id' => $eventId,
            'summary' => $event['summary'],
            'description' => $event['description'],
            'colorId' => $event['colorId'] ?? null,
            'start' => $start,
            'end' => $end,
            'transparency' => 'opaque',
            'visibility' => 'private',
            'extendedProperties' => [
                'private' => $event['privateProperties'] ?? [],
            ],
        ]);

        $created = $this->calendar->events->insert($this->calendarId, $googleEvent);
        return ['id' => $created->getId()];
    }

    public function get(string $eventId): ?array
    {
        try {
            $event = $this->calendar->events->get($this->calendarId, $eventId);
        } catch (GoogleServiceException $error) {
            if ($error->getCode() === 404 || $error->getCode() === 410) {
                return null;
            }
            throw $error;
        }

        $private = $event->getExtendedProperties()?->getPrivate() ?? [];
        return [
            'id' => $event->getId(),
            'privateProperties' => $private,
        ];
    }
}

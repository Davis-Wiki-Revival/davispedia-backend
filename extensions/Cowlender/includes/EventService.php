<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender;

use MediaWiki\Extension\Cowlender\Domain\EventFormatter;
use MediaWiki\Extension\Cowlender\Domain\EventNotFoundException;
use MediaWiki\Extension\Cowlender\Domain\EventValidator;
use MediaWiki\Extension\Cowlender\Store\EventStore;
use MediaWiki\User\UserIdentity;

/**
 * Application layer between REST handlers and persistence.
 */
final class EventService {
	private EventStore $store;
	private EventValidator $validator;
	private EventFormatter $formatter;

	public function __construct(
		EventStore $store,
		EventValidator $validator,
		EventFormatter $formatter
	) {
		$this->store = $store;
		$this->validator = $validator;
		$this->formatter = $formatter;
	}

	public function listEvents( string $start, string $end, ?int $limit ): array {
		$range = $this->validator->validateRange( $start, $end, $limit );
		$result = $this->store->listEvents( $range );

		return [
			'events' => array_map(
				fn ( array $event ): array => $this->formatter->format( $event ),
				$result['events']
			),
			'range' => [
				'start' => $start,
				'end' => $end,
			],
			'truncated' => $result['truncated'],
		];
	}

	public function getEvent( int $eventId, bool $primary = false ): array {
		$event = $this->getCanonicalEvent( $eventId, $primary );
		return $this->formatter->format( $event );
	}

	public function getCanonicalEvent( int $eventId, bool $primary = false ): array {
		$event = $this->store->getEvent( $eventId, $primary );
		if ( $event === null ) {
			throw new EventNotFoundException( $eventId );
		}

		return $event;
	}

	public function createEvent( array $input, UserIdentity $actor ): array {
		$event = $this->validator->validateCreate( $input );
		return $this->formatter->format( $this->store->createEvent( $event, $actor ) );
	}

	public function updateEvent( int $eventId, array $input, UserIdentity $actor ): array {
		$existing = $this->getCanonicalEvent( $eventId, true );
		$validated = $this->validator->validateUpdate( $input, $existing );
		$updated = $this->store->updateEvent(
			$eventId,
			$validated['expectedVersion'],
			$validated['event'],
			$actor
		);

		return $this->formatter->format( $updated );
	}

	public function deleteEvent( int $eventId, int $version, UserIdentity $actor ): array {
		$deleted = $this->store->deleteEvent( $eventId, $version, $actor );
		return $this->formatter->format( $deleted );
	}

	public function listRevisions( int $eventId ): array {
		$revisions = $this->store->listRevisions( $eventId );
		if ( $revisions === [] && $this->store->getEvent( $eventId ) === null ) {
			throw new EventNotFoundException( $eventId );
		}

		return array_map( function ( array $revision ): array {
			return [
				'id' => $revision['id'],
				'eventId' => $revision['eventId'],
				'eventVersion' => $revision['eventVersion'],
				'action' => $revision['action'],
				'actor' => [
					'id' => $revision['actorId'],
					'name' => $revision['actorName'],
				],
				'changedAt' => $this->formatter->formatUtcTimestamp( $revision['changedAt'] ),
				'event' => $this->formatter->format( $revision['snapshot'] ),
			];
		}, $revisions );
	}

	public function getMetadata(): array {
		return [
			'apiVersion' => 1,
			'defaultTimezone' => $this->validator->getDefaultTimezone(),
			'statuses' => EventValidator::STATUSES,
			'categories' => $this->validator->getCategories(),
			'limits' => [
				'maxRangeDays' => $this->validator->getMaxRangeDays(),
				'maxEventsPerRequest' => $this->validator->getMaxEventsPerRequest(),
			],
			'recurrenceSupported' => false,
		];
	}
}

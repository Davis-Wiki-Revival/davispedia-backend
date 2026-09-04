<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender\Store;

use JsonException;
use MediaWiki\Extension\Cowlender\Domain\EventNotFoundException;
use MediaWiki\Extension\Cowlender\Domain\VersionConflictException;
use MediaWiki\User\UserIdentity;
use RuntimeException;
use stdClass;
use Throwable;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;

/**
 * All persistence for Cowlender events and their audit history.
 */
final class EventStore {
	private const EVENT_FIELDS = [
		'cwe_id',
		'cwe_title',
		'cwe_description',
		'cwe_location',
		'cwe_start_utc',
		'cwe_end_utc',
		'cwe_start_date',
		'cwe_end_date',
		'cwe_timezone',
		'cwe_all_day',
		'cwe_status',
		'cwe_category',
		'cwe_external_url',
		'cwe_recurrence_rule',
		'cwe_created_by',
		'cwe_created_by_name',
		'cwe_created_at',
		'cwe_updated_by',
		'cwe_updated_by_name',
		'cwe_updated_at',
		'cwe_version',
	];

	private IConnectionProvider $connections;

	public function __construct( IConnectionProvider $connections ) {
		$this->connections = $connections;
	}

	/**
	 * @return array{events:array,truncated:bool}
	 */
	public function listEvents( array $range ): array {
		$db = $this->connections->getReplicaDatabase();
		$perQueryLimit = $range['limit'] + 1;

		$timedRows = $db->select(
			'cowlender_event',
			self::EVENT_FIELDS,
			[
				'cwe_all_day' => 0,
				'cwe_start_utc < ' . $db->addQuotes( $range['endUtc'] ),
				'cwe_end_utc > ' . $db->addQuotes( $range['startUtc'] ),
			],
			__METHOD__,
			[
				'ORDER BY' => 'cwe_start_utc ASC, cwe_id ASC',
				'LIMIT' => $perQueryLimit,
			]
		);

		$allDayRows = $db->select(
			'cowlender_event',
			self::EVENT_FIELDS,
			[
				'cwe_all_day' => 1,
				'cwe_start_date < ' . $db->addQuotes( $range['endDate'] ),
				'cwe_end_date > ' . $db->addQuotes( $range['startDate'] ),
			],
			__METHOD__,
			[
				'ORDER BY' => 'cwe_start_date ASC, cwe_id ASC',
				'LIMIT' => $perQueryLimit,
			]
		);

		$events = [];
		foreach ( $timedRows as $row ) {
			$events[] = $this->rowToEvent( $row );
		}
		foreach ( $allDayRows as $row ) {
			$events[] = $this->rowToEvent( $row );
		}

		usort( $events, static function ( array $left, array $right ): int {
			$leftStart = $left['allDay'] ? $left['startDate'] . '000000' : $left['startUtc'];
			$rightStart = $right['allDay'] ? $right['startDate'] . '000000' : $right['startUtc'];
			return [ $leftStart, $left['id'] ] <=> [ $rightStart, $right['id'] ];
		} );

		$truncated = count( $events ) > $range['limit'];
		if ( $truncated ) {
			$events = array_slice( $events, 0, $range['limit'] );
		}

		return [
			'events' => $events,
			'truncated' => $truncated,
		];
	}

	public function getEvent( int $eventId, bool $primary = false ): ?array {
		$db = $primary
			? $this->connections->getPrimaryDatabase()
			: $this->connections->getReplicaDatabase();
		$row = $db->selectRow(
			'cowlender_event',
			self::EVENT_FIELDS,
			[ 'cwe_id' => $eventId ],
			__METHOD__
		);

		return $row === false ? null : $this->rowToEvent( $row );
	}

	public function createEvent( array $event, UserIdentity $actor ): array {
		$db = $this->connections->getPrimaryDatabase();
		$now = gmdate( 'YmdHis' );
		$row = $this->eventFieldsToRow( $event ) + [
			'cwe_created_by' => $actor->getId(),
			'cwe_created_by_name' => $actor->getName(),
			'cwe_created_at' => $now,
			'cwe_updated_by' => $actor->getId(),
			'cwe_updated_by_name' => $actor->getName(),
			'cwe_updated_at' => $now,
			'cwe_version' => 1,
		];

		$db->startAtomic( __METHOD__, IDatabase::ATOMIC_CANCELABLE );
		try {
			$db->insert( 'cowlender_event', $row, __METHOD__ );
			$eventId = (int)$db->insertId();
			$created = $this->getEventFromDatabase( $db, $eventId );
			if ( $created === null ) {
				throw new RuntimeException( 'Cowlender could not reload a newly created event.' );
			}
			$this->insertRevision( $db, 'create', $created, $actor );
			$db->endAtomic( __METHOD__ );
			return $created;
		} catch ( Throwable $exception ) {
			$db->cancelAtomic( __METHOD__ );
			throw $exception;
		}
	}

	public function updateEvent(
		int $eventId,
		int $expectedVersion,
		array $event,
		UserIdentity $actor
	): array {
		$db = $this->connections->getPrimaryDatabase();
		$db->startAtomic( __METHOD__, IDatabase::ATOMIC_CANCELABLE );

		try {
			$current = $this->getEventForUpdate( $db, $eventId );
			if ( $current === null ) {
				throw new EventNotFoundException( $eventId );
			}
			if ( $current['version'] !== $expectedVersion ) {
				throw new VersionConflictException( $eventId, $expectedVersion, $current['version'] );
			}

			$newVersion = $current['version'] + 1;
			$row = $this->eventFieldsToRow( $event ) + [
				'cwe_updated_by' => $actor->getId(),
				'cwe_updated_by_name' => $actor->getName(),
				'cwe_updated_at' => gmdate( 'YmdHis' ),
				'cwe_version' => $newVersion,
			];

			$db->update(
				'cowlender_event',
				$row,
				[
					'cwe_id' => $eventId,
					'cwe_version' => $expectedVersion,
				],
				__METHOD__
			);

			if ( $db->affectedRows() !== 1 ) {
				$this->throwConcurrentChange( $db, $eventId, $expectedVersion );
			}

			$updated = $this->getEventFromDatabase( $db, $eventId );
			if ( $updated === null ) {
				throw new EventNotFoundException( $eventId );
			}
			$this->insertRevision( $db, 'update', $updated, $actor );
			$db->endAtomic( __METHOD__ );
			return $updated;
		} catch ( Throwable $exception ) {
			$db->cancelAtomic( __METHOD__ );
			throw $exception;
		}
	}

	public function deleteEvent( int $eventId, int $expectedVersion, UserIdentity $actor ): array {
		$db = $this->connections->getPrimaryDatabase();
		$db->startAtomic( __METHOD__, IDatabase::ATOMIC_CANCELABLE );

		try {
			$current = $this->getEventForUpdate( $db, $eventId );
			if ( $current === null ) {
				throw new EventNotFoundException( $eventId );
			}
			if ( $current['version'] !== $expectedVersion ) {
				throw new VersionConflictException( $eventId, $expectedVersion, $current['version'] );
			}

			$deleted = $current;
			$deleted['version'] = $current['version'] + 1;
			$deleted['updatedById'] = $actor->getId();
			$deleted['updatedByName'] = $actor->getName();
			$deleted['updatedAt'] = gmdate( 'YmdHis' );
			$this->insertRevision( $db, 'delete', $deleted, $actor );

			$db->delete(
				'cowlender_event',
				[
					'cwe_id' => $eventId,
					'cwe_version' => $expectedVersion,
				],
				__METHOD__
			);
			if ( $db->affectedRows() !== 1 ) {
				$this->throwConcurrentChange( $db, $eventId, $expectedVersion );
			}

			$db->endAtomic( __METHOD__ );
			return $deleted;
		} catch ( Throwable $exception ) {
			$db->cancelAtomic( __METHOD__ );
			throw $exception;
		}
	}

	public function listRevisions( int $eventId, int $limit = 200 ): array {
		$db = $this->connections->getReplicaDatabase();
		$rows = $db->select(
			'cowlender_event_revision',
			[
				'cwr_id',
				'cwr_event_id',
				'cwr_event_version',
				'cwr_action',
				'cwr_actor_id',
				'cwr_actor_name',
				'cwr_changed_at',
				'cwr_snapshot',
			],
			[ 'cwr_event_id' => $eventId ],
			__METHOD__,
			[
				'ORDER BY' => 'cwr_id DESC',
				'LIMIT' => $limit,
			]
		);

		$revisions = [];
		foreach ( $rows as $row ) {
			try {
				$snapshot = json_decode( $row->cwr_snapshot, true, 512, JSON_THROW_ON_ERROR );
			} catch ( JsonException $exception ) {
				throw new RuntimeException( 'A Cowlender revision contains invalid JSON.', 0, $exception );
			}

			$revisions[] = [
				'id' => (int)$row->cwr_id,
				'eventId' => (int)$row->cwr_event_id,
				'eventVersion' => (int)$row->cwr_event_version,
				'action' => (string)$row->cwr_action,
				'actorId' => (int)$row->cwr_actor_id,
				'actorName' => (string)$row->cwr_actor_name,
				'changedAt' => (string)$row->cwr_changed_at,
				'snapshot' => $snapshot,
			];
		}

		return $revisions;
	}

	private function getEventForUpdate( IDatabase $db, int $eventId ): ?array {
		$options = $db->getType() === 'sqlite' ? [] : [ 'FOR UPDATE' ];
		$row = $db->selectRow(
			'cowlender_event',
			self::EVENT_FIELDS,
			[ 'cwe_id' => $eventId ],
			__METHOD__,
			$options
		);

		return $row === false ? null : $this->rowToEvent( $row );
	}

	private function getEventFromDatabase( IDatabase $db, int $eventId ): ?array {
		$row = $db->selectRow(
			'cowlender_event',
			self::EVENT_FIELDS,
			[ 'cwe_id' => $eventId ],
			__METHOD__
		);

		return $row === false ? null : $this->rowToEvent( $row );
	}

	private function throwConcurrentChange( IDatabase $db, int $eventId, int $expectedVersion ): never {
		$current = $this->getEventFromDatabase( $db, $eventId );
		if ( $current === null ) {
			throw new EventNotFoundException( $eventId );
		}

		throw new VersionConflictException( $eventId, $expectedVersion, $current['version'] );
	}

	private function insertRevision(
		IDatabase $db,
		string $action,
		array $event,
		UserIdentity $actor
	): void {
		try {
			$snapshot = json_encode(
				$event,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
		} catch ( JsonException $exception ) {
			throw new RuntimeException( 'Cowlender could not serialize an event revision.', 0, $exception );
		}

		$db->insert(
			'cowlender_event_revision',
			[
				'cwr_event_id' => $event['id'],
				'cwr_event_version' => $event['version'],
				'cwr_action' => $action,
				'cwr_actor_id' => $actor->getId(),
				'cwr_actor_name' => $actor->getName(),
				'cwr_changed_at' => gmdate( 'YmdHis' ),
				'cwr_snapshot' => $snapshot,
			],
			__METHOD__
		);
	}

	private function eventFieldsToRow( array $event ): array {
		return [
			'cwe_title' => $event['title'],
			'cwe_description' => $event['description'],
			'cwe_location' => $event['location'],
			'cwe_start_utc' => $event['startUtc'],
			'cwe_end_utc' => $event['endUtc'],
			'cwe_start_date' => $event['startDate'],
			'cwe_end_date' => $event['endDate'],
			'cwe_timezone' => $event['timezone'],
			'cwe_all_day' => $event['allDay'] ? 1 : 0,
			'cwe_status' => $event['status'],
			'cwe_category' => $event['category'],
			'cwe_external_url' => $event['externalUrl'],
			'cwe_recurrence_rule' => $event['recurrenceRule'],
		];
	}

	private function rowToEvent( stdClass $row ): array {
		return [
			'id' => (int)$row->cwe_id,
			'title' => (string)$row->cwe_title,
			'description' => (string)$row->cwe_description,
			'location' => (string)$row->cwe_location,
			'startUtc' => $row->cwe_start_utc === null ? null : (string)$row->cwe_start_utc,
			'endUtc' => $row->cwe_end_utc === null ? null : (string)$row->cwe_end_utc,
			'startDate' => $row->cwe_start_date === null ? null : (string)$row->cwe_start_date,
			'endDate' => $row->cwe_end_date === null ? null : (string)$row->cwe_end_date,
			'timezone' => (string)$row->cwe_timezone,
			'allDay' => (bool)$row->cwe_all_day,
			'status' => (string)$row->cwe_status,
			'category' => $row->cwe_category === null ? null : (string)$row->cwe_category,
			'externalUrl' => $row->cwe_external_url === null ? null : (string)$row->cwe_external_url,
			'recurrenceRule' => $row->cwe_recurrence_rule === null
				? null
				: (string)$row->cwe_recurrence_rule,
			'createdById' => (int)$row->cwe_created_by,
			'createdByName' => (string)$row->cwe_created_by_name,
			'createdAt' => (string)$row->cwe_created_at,
			'updatedById' => (int)$row->cwe_updated_by,
			'updatedByName' => (string)$row->cwe_updated_by_name,
			'updatedAt' => (string)$row->cwe_updated_at,
			'version' => (int)$row->cwe_version,
		];
	}
}

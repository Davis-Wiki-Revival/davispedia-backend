<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender\Domain;

use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

/**
 * Converts the storage representation into the public JSON representation.
 */
final class EventFormatter {
	public function format( array $event ): array {
		$allDay = (bool)$event['allDay'];
		$timezone = $event['timezone'];

		if ( $allDay ) {
			$start = $this->formatDate( $event['startDate'] );
			$end = $this->formatDate( $event['endDate'] );
		} else {
			$start = $this->formatTimestampInTimezone( $event['startUtc'], $timezone );
			$end = $this->formatTimestampInTimezone( $event['endUtc'], $timezone );
		}

		return [
			'id' => (int)$event['id'],
			'title' => $event['title'],
			'description' => $event['description'],
			'location' => $event['location'],
			'start' => $start,
			'end' => $end,
			'allDay' => $allDay,
			'timezone' => $timezone,
			'status' => $event['status'],
			'category' => $event['category'],
			'externalUrl' => $event['externalUrl'],
			'recurrenceRule' => $event['recurrenceRule'],
			'createdBy' => [
				'id' => (int)$event['createdById'],
				'name' => $event['createdByName'],
			],
			'createdAt' => $this->formatUtcTimestamp( $event['createdAt'] ),
			'updatedBy' => [
				'id' => (int)$event['updatedById'],
				'name' => $event['updatedByName'],
			],
			'updatedAt' => $this->formatUtcTimestamp( $event['updatedAt'] ),
			'version' => (int)$event['version'],
		];
	}

	public function formatUtcTimestamp( string $timestamp ): string {
		$date = DateTimeImmutable::createFromFormat(
			'!YmdHis',
			$timestamp,
			new DateTimeZone( 'UTC' )
		);
		if ( $date === false ) {
			throw new UnexpectedValueException( 'Invalid timestamp read from the Cowlender database.' );
		}

		return $date->format( 'Y-m-d\\TH:i:s\\Z' );
	}

	private function formatTimestampInTimezone( string $timestamp, string $timezone ): string {
		$date = DateTimeImmutable::createFromFormat(
			'!YmdHis',
			$timestamp,
			new DateTimeZone( 'UTC' )
		);
		if ( $date === false ) {
			throw new UnexpectedValueException( 'Invalid event timestamp read from the Cowlender database.' );
		}

		return $date->setTimezone( new DateTimeZone( $timezone ) )->format( DATE_ATOM );
	}

	private function formatDate( string $date ): string {
		$parsed = DateTimeImmutable::createFromFormat( '!Ymd', $date, new DateTimeZone( 'UTC' ) );
		if ( $parsed === false ) {
			throw new UnexpectedValueException( 'Invalid all-day date read from the Cowlender database.' );
		}

		return $parsed->format( 'Y-m-d' );
	}
}

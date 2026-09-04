<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender\Domain;

use Config;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Validates client input and converts it to Cowlender's canonical representation.
 */
final class EventValidator {
	public const STATUSES = [ 'scheduled', 'tentative', 'cancelled' ];

	private const EVENT_FIELDS = [
		'title',
		'description',
		'location',
		'start',
		'end',
		'allDay',
		'timezone',
		'status',
		'category',
		'externalUrl',
	];

	private Config $config;
	private array $categoriesBySlug;

	public function __construct( Config $config ) {
		$this->config = $config;
		$this->categoriesBySlug = [];

		foreach ( $config->get( 'CowlenderCategories' ) as $category ) {
			if ( !is_array( $category ) || !isset( $category['slug'] ) ) {
				continue;
			}

			$slug = (string)$category['slug'];
			if ( preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug ) !== 1 ) {
				continue;
			}
			$label = isset( $category['label'] ) ? trim( (string)$category['label'] ) : $slug;
			$color = isset( $category['color'] ) ? (string)$category['color'] : '#6b7280';
			if ( preg_match( '/^#[0-9a-fA-F]{6}$/D', $color ) !== 1 ) {
				$color = '#6b7280';
			}

			$this->categoriesBySlug[$slug] = [
				'slug' => $slug,
				'label' => $label === '' ? $slug : $label,
				'color' => strtolower( $color ),
			];
		}
	}

	/**
	 * @return array Canonical event fields suitable for EventStore.
	 */
	public function validateCreate( array $input ): array {
		$this->assertKnownFields( $input, self::EVENT_FIELDS );

		$payload = [
			'title' => $input['title'] ?? null,
			'description' => $input['description'] ?? '',
			'location' => $input['location'] ?? '',
			'start' => $input['start'] ?? null,
			'end' => $input['end'] ?? null,
			'allDay' => $input['allDay'] ?? false,
			'timezone' => $input['timezone'] ?? $this->getDefaultTimezone(),
			'status' => $input['status'] ?? 'scheduled',
			'category' => $input['category'] ?? null,
			'externalUrl' => $input['externalUrl'] ?? null,
		];

		return $this->normalizeEvent( $payload );
	}

	/**
	 * @param array $existing Canonical event returned by EventStore.
	 * @return array{event:array,expectedVersion:int}
	 */
	public function validateUpdate( array $input, array $existing ): array {
		$this->assertKnownFields( $input, array_merge( self::EVENT_FIELDS, [ 'version' ] ) );

		if ( !array_key_exists( 'version', $input ) || !is_int( $input['version'] ) || $input['version'] < 1 ) {
			$this->invalid( 'version', 'A positive integer version is required for updates.' );
		}

		$changes = $input;
		unset( $changes['version'] );
		if ( $changes === [] ) {
			throw new ValidationException( 'At least one event field must be changed.' );
		}

		$existingPayload = $this->canonicalToPayload( $existing );
		$changesAllDayMode = array_key_exists( 'allDay', $changes )
			&& is_bool( $changes['allDay'] )
			&& $changes['allDay'] !== $existingPayload['allDay'];

		if ( $changesAllDayMode && ( !array_key_exists( 'start', $changes ) || !array_key_exists( 'end', $changes ) ) ) {
			throw new ValidationException(
				'Changing allDay requires new start and end values.',
				[ 'allDay' => 'Supply start and end whenever allDay changes.' ]
			);
		}

		return [
			'event' => $this->normalizeEvent( array_replace( $existingPayload, $changes ) ),
			'expectedVersion' => $input['version'],
		];
	}

	/**
	 * Validate a FullCalendar-style half-open query interval.
	 *
	 * @return array{startUtc:string,endUtc:string,startDate:string,endDate:string,limit:int}
	 */
	public function validateRange( string $start, string $end, ?int $limit ): array {
		$timezone = new DateTimeZone( $this->getDefaultTimezone() );
		$startDateTime = $this->parseBoundary( $start, $timezone, 'start' );
		$endDateTime = $this->parseBoundary( $end, $timezone, 'end' );

		if ( $endDateTime <= $startDateTime ) {
			throw new ValidationException(
				'The range end must be after the range start.',
				[ 'end' => 'Must be later than start.' ]
			);
		}

		$maxRangeDays = $this->getMaxRangeDays();
		$rangeDays = (int)$startDateTime->diff( $endDateTime )->days;
		if ( $rangeDays > $maxRangeDays ) {
			throw new ValidationException(
				'The requested range is too large.',
				[ 'end' => "Ranges may span at most {$maxRangeDays} days." ]
			);
		}

		$maxLimit = $this->getMaxEventsPerRequest();
		$resolvedLimit = $limit ?? $maxLimit;
		if ( $resolvedLimit < 1 || $resolvedLimit > $maxLimit ) {
			throw new ValidationException(
				'The requested limit is invalid.',
				[ 'limit' => "Must be between 1 and {$maxLimit}." ]
			);
		}

		$localStart = $startDateTime->setTimezone( $timezone );
		$localEnd = $endDateTime->setTimezone( $timezone );
		$endDateExclusive = $localEnd->format( 'His' ) === '000000'
			? $localEnd
			: $localEnd->modify( '+1 day' );

		$utc = new DateTimeZone( 'UTC' );

		return [
			'startUtc' => $startDateTime->setTimezone( $utc )->format( 'YmdHis' ),
			'endUtc' => $endDateTime->setTimezone( $utc )->format( 'YmdHis' ),
			'startDate' => $localStart->format( 'Ymd' ),
			'endDate' => $endDateExclusive->format( 'Ymd' ),
			'limit' => $resolvedLimit,
		];
	}

	public function getDefaultTimezone(): string {
		return (string)$this->config->get( 'CowlenderDefaultTimezone' );
	}

	public function getMaxRangeDays(): int {
		return (int)$this->config->get( 'CowlenderMaxRangeDays' );
	}

	public function getMaxEventsPerRequest(): int {
		return (int)$this->config->get( 'CowlenderMaxEventsPerRequest' );
	}

	public function getCategories(): array {
		return array_values( $this->categoriesBySlug );
	}

	private function normalizeEvent( array $payload ): array {
		$title = $this->requiredString( $payload['title'], 'title', 255, 1020 );
		$description = $this->optionalString( $payload['description'], 'description', 20000, 80000 ) ?? '';
		$location = $this->optionalString( $payload['location'], 'location', 500, 2000 ) ?? '';

		if ( !is_bool( $payload['allDay'] ) ) {
			$this->invalid( 'allDay', 'Must be a boolean.' );
		}
		$allDay = $payload['allDay'];

		$timezone = $this->requiredString( $payload['timezone'], 'timezone', 64, 64 );
		if ( !in_array( $timezone, DateTimeZone::listIdentifiers(), true ) && $timezone !== 'UTC' ) {
			$this->invalid( 'timezone', 'Must be a recognized IANA timezone, such as America/Los_Angeles.' );
		}

		$status = $this->requiredString( $payload['status'], 'status', 16, 16 );
		if ( !in_array( $status, self::STATUSES, true ) ) {
			$this->invalid( 'status', 'Must be scheduled, tentative, or cancelled.' );
		}

		$category = $this->optionalString( $payload['category'], 'category', 64, 64 );
		if ( $category !== null && !isset( $this->categoriesBySlug[$category] ) ) {
			$this->invalid( 'category', 'Must be one of the configured category slugs.' );
		}

		$externalUrl = $this->validateExternalUrl( $payload['externalUrl'] );

		if ( $allDay ) {
			$startDate = $this->parseDate( $payload['start'], 'start' );
			$endDate = $this->parseDate( $payload['end'], 'end' );
			if ( $endDate <= $startDate ) {
				$this->invalid( 'end', 'For an all-day event, end is exclusive and must be after start.' );
			}

			$durationDays = $startDate->diff( $endDate )->days;
			$this->validateDurationDays( (int)$durationDays );

			$startUtc = null;
			$endUtc = null;
			$startDateValue = $startDate->format( 'Ymd' );
			$endDateValue = $endDate->format( 'Ymd' );
		} else {
			$startDateTime = $this->parseTimestamp( $payload['start'], 'start' );
			$endDateTime = $this->parseTimestamp( $payload['end'], 'end' );
			if ( $endDateTime <= $startDateTime ) {
				$this->invalid( 'end', 'Must be later than start.' );
			}

			$durationSeconds = $endDateTime->getTimestamp() - $startDateTime->getTimestamp();
			$this->validateDurationDays( $durationSeconds / 86400 );

			$utc = new DateTimeZone( 'UTC' );
			$startUtc = $startDateTime->setTimezone( $utc )->format( 'YmdHis' );
			$endUtc = $endDateTime->setTimezone( $utc )->format( 'YmdHis' );
			$startDateValue = null;
			$endDateValue = null;
		}

		return [
			'title' => $title,
			'description' => $description,
			'location' => $location,
			'startUtc' => $startUtc,
			'endUtc' => $endUtc,
			'startDate' => $startDateValue,
			'endDate' => $endDateValue,
			'allDay' => $allDay,
			'timezone' => $timezone,
			'status' => $status,
			'category' => $category,
			'externalUrl' => $externalUrl,
			'recurrenceRule' => null,
		];
	}

	private function canonicalToPayload( array $event ): array {
		$allDay = (bool)$event['allDay'];
		if ( $allDay ) {
			$start = $this->storedDateToIso( $event['startDate'] );
			$end = $this->storedDateToIso( $event['endDate'] );
		} else {
			$timezone = new DateTimeZone( $event['timezone'] );
			$start = $this->storedTimestampToDateTime( $event['startUtc'] )
				->setTimezone( $timezone )
				->format( DATE_ATOM );
			$end = $this->storedTimestampToDateTime( $event['endUtc'] )
				->setTimezone( $timezone )
				->format( DATE_ATOM );
		}

		return [
			'title' => $event['title'],
			'description' => $event['description'],
			'location' => $event['location'],
			'start' => $start,
			'end' => $end,
			'allDay' => $allDay,
			'timezone' => $event['timezone'],
			'status' => $event['status'],
			'category' => $event['category'],
			'externalUrl' => $event['externalUrl'],
		];
	}

	private function parseBoundary( string $value, DateTimeZone $defaultTimezone, string $field ): DateTimeImmutable {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) === 1 ) {
			$this->parseDate( $value, $field );
			$localMidnight = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $defaultTimezone );
			if ( $localMidnight === false ) {
				$this->invalid( $field, 'Must be a real calendar date.' );
			}
			return $localMidnight;
		}

		return $this->parseTimestamp( $value, $field );
	}

	private function parseDate( mixed $value, string $field ): DateTimeImmutable {
		if ( !is_string( $value ) || preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $match ) !== 1 ) {
			$this->invalid( $field, 'Must use YYYY-MM-DD for an all-day date.' );
		}

		if ( !checkdate( (int)$match[2], (int)$match[3], (int)$match[1] ) ) {
			$this->invalid( $field, 'Must be a real calendar date.' );
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );
		if ( $date === false ) {
			$this->invalid( $field, 'Must be a real calendar date.' );
		}

		return $date;
	}

	private function parseTimestamp( mixed $value, string $field ): DateTimeImmutable {
		$pattern = '/^(\d{4})-(\d{2})-(\d{2})T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D';
		if ( !is_string( $value ) || preg_match( $pattern, $value, $match ) !== 1 ) {
			$this->invalid(
				$field,
				'Must be an RFC 3339 timestamp with seconds and an explicit offset.'
			);
		}

		if ( !checkdate( (int)$match[2], (int)$match[3], (int)$match[1] ) ) {
			$this->invalid( $field, 'Must contain a real calendar date.' );
		}

		try {
			return new DateTimeImmutable( $value );
		} catch ( Throwable ) {
			$this->invalid( $field, 'Must be a valid RFC 3339 timestamp.' );
		}
	}

	private function validateExternalUrl( mixed $value ): ?string {
		$url = $this->optionalString( $value, 'externalUrl', 2048, 8192 );
		if ( $url === null ) {
			return null;
		}

		$parts = parse_url( $url );
		if (
			$parts === false
			|| !isset( $parts['scheme'], $parts['host'] )
			|| !in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true )
		) {
			$this->invalid( 'externalUrl', 'Must be an absolute HTTP or HTTPS URL.' );
		}

		return $url;
	}

	private function requiredString( mixed $value, string $field, int $maxCharacters, int $maxBytes ): string {
		$normalized = $this->optionalString( $value, $field, $maxCharacters, $maxBytes );
		if ( $normalized === null ) {
			$this->invalid( $field, 'This field is required.' );
		}

		return $normalized;
	}

	private function optionalString(
		mixed $value,
		string $field,
		int $maxCharacters,
		int $maxBytes
	): ?string {
		if ( $value === null || $value === '' ) {
			return null;
		}
		if ( !is_string( $value ) ) {
			$this->invalid( $field, 'Must be a string or null.' );
		}

		$normalized = trim( $value );
		if ( $normalized === '' ) {
			return null;
		}
		if ( str_contains( $normalized, "\0" ) ) {
			$this->invalid( $field, 'May not contain null bytes.' );
		}
		if ( mb_strlen( $normalized ) > $maxCharacters || strlen( $normalized ) > $maxBytes ) {
			$this->invalid( $field, "Must be no longer than {$maxCharacters} characters." );
		}

		return $normalized;
	}

	private function validateDurationDays( int|float $durationDays ): void {
		$maxDurationDays = (int)$this->config->get( 'CowlenderMaxEventDurationDays' );
		if ( $durationDays > $maxDurationDays ) {
			$this->invalid( 'end', "An event may span at most {$maxDurationDays} days." );
		}
	}

	private function storedTimestampToDateTime( string $timestamp ): DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat(
			'!YmdHis',
			$timestamp,
			new DateTimeZone( 'UTC' )
		);
		if ( $date === false ) {
			throw new ValidationException( 'Stored event data contains an invalid timestamp.' );
		}

		return $date;
	}

	private function storedDateToIso( string $date ): string {
		return substr( $date, 0, 4 ) . '-' . substr( $date, 4, 2 ) . '-' . substr( $date, 6, 2 );
	}

	private function assertKnownFields( array $input, array $knownFields ): void {
		$unknownFields = array_values( array_diff( array_keys( $input ), $knownFields ) );
		if ( $unknownFields !== [] ) {
			throw new ValidationException(
				'The request contains unsupported fields.',
				[ 'unknownFields' => $unknownFields ]
			);
		}
	}

	private function invalid( string $field, string $message ): never {
		throw new ValidationException( 'The event data is invalid.', [ $field => $message ] );
	}
}

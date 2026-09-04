<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender\Domain;

final class VersionConflictException extends CowlenderException {
	public function __construct( int $eventId, int $expectedVersion, int $currentVersion ) {
		parent::__construct(
			409,
			'version_conflict',
			'This event changed after it was loaded. Refresh it and try again.',
			[
				'eventId' => $eventId,
				'expectedVersion' => $expectedVersion,
				'currentVersion' => $currentVersion,
			]
		);
	}
}

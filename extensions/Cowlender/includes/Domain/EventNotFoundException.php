<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender\Domain;

final class EventNotFoundException extends CowlenderException {
	public function __construct( int $eventId ) {
		parent::__construct(
			404,
			'event_not_found',
			'The requested Cowlender event does not exist.',
			[ 'eventId' => $eventId ]
		);
	}
}

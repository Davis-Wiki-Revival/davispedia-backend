<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender\Domain;

final class ValidationException extends CowlenderException {
	public function __construct( string $message, array $errors = [] ) {
		parent::__construct( 400, 'invalid_event', $message, $errors );
	}
}

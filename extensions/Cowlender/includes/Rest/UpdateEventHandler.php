<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender\Rest;

use Wikimedia\ParamValidator\ParamValidator;

final class UpdateEventHandler extends AbstractCowlenderHandler {
	public function execute() {
		return $this->handle( function () {
			$params = $this->getValidatedParams();
			$eventId = (int)$params['id'];
			$existing = $this->service()->getCanonicalEvent( $eventId, true );
			$user = $this->assertCanEdit( $existing );
			$this->assertValidCsrfToken( $user );

			return $this->jsonResponse( [
				'event' => $this->service()->updateEvent( $eventId, $this->parseJsonObject(), $user ),
			] );
		} );
	}

	public function getParamSettings(): array {
		return [
			'id' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	public function needsWriteAccess(): bool {
		return true;
	}
}

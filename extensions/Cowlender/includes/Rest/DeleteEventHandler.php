<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender\Rest;

use Wikimedia\ParamValidator\ParamValidator;

final class DeleteEventHandler extends AbstractCowlenderHandler {
	public function execute() {
		return $this->handle( function () {
			$user = $this->assertRegisteredWithRight( 'cowlender-delete' );
			$this->assertValidCsrfToken( $user );
			$params = $this->getValidatedParams();
			$event = $this->service()->deleteEvent(
				(int)$params['id'],
				(int)$params['version'],
				$user
			);

			return $this->jsonResponse( [
				'deleted' => true,
				'event' => $event,
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
			'version' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	public function needsWriteAccess(): bool {
		return true;
	}
}

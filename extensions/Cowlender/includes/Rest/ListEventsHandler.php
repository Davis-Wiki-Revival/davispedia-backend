<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender\Rest;

use Wikimedia\ParamValidator\ParamValidator;

final class ListEventsHandler extends AbstractCowlenderHandler {
	public function execute() {
		return $this->handle( function () {
			$this->assertRight( 'cowlender-view' );
			$params = $this->getValidatedParams();
			$limit = isset( $params['limit'] ) ? (int)$params['limit'] : null;

			return $this->jsonResponse(
				$this->service()->listEvents( (string)$params['start'], (string)$params['end'], $limit )
			);
		} );
	}

	public function getParamSettings(): array {
		return [
			'start' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'end' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'limit' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
			],
		];
	}
}

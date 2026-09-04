<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender\Rest;

use Wikimedia\ParamValidator\ParamValidator;

final class ListRevisionsHandler extends AbstractCowlenderHandler {
	public function execute() {
		return $this->handle( function () {
			$this->assertRight( 'cowlender-view' );
			$params = $this->getValidatedParams();
			return $this->jsonResponse( [
				'revisions' => $this->service()->listRevisions( (int)$params['id'] ),
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
}

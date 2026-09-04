<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender\Rest;

final class CreateEventHandler extends AbstractCowlenderHandler {
	public function execute() {
		return $this->handle( function () {
			$user = $this->assertRegisteredWithRight( 'cowlender-create' );
			$this->assertValidCsrfToken( $user );
			$event = $this->service()->createEvent( $this->parseJsonObject(), $user );

			$response = $this->jsonResponse( [ 'event' => $event ], 201 );
			$response->setHeader(
				'Location',
				wfScript( 'rest' ) . '/cowlender/v1/events/' . $event['id']
			);
			return $response;
		} );
	}

	public function needsWriteAccess(): bool {
		return true;
	}
}

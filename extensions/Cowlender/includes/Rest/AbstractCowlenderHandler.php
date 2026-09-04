<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender\Rest;

use JsonException;
use MediaWiki\Extension\Cowlender\Domain\CowlenderException;
use MediaWiki\Extension\Cowlender\EventService;
use MediaWiki\MediaWikiServices;
use MediaWiki\Rest\Handler;
use MediaWiki\User\UserIdentity;

/**
 * Shared JSON, authentication, permission, and error behavior for the REST API.
 */
abstract class AbstractCowlenderHandler extends Handler {
	protected const MAX_REQUEST_BYTES = 131072;

	protected function service(): EventService {
		/** @var EventService $service */
		$service = MediaWikiServices::getInstance()->getService( 'Cowlender.EventService' );
		return $service;
	}

	protected function handle( callable $callback ) {
		try {
			return $callback();
		} catch ( CowlenderException $exception ) {
			return $this->problemResponse( $exception );
		}
	}

	protected function jsonResponse( array $body, int $status = 200 ) {
		$response = $this->getResponseFactory()->createJson( $body );
		$response->setStatus( $status );
		$response->setHeader( 'Cache-Control', 'private, no-store' );
		$response->setHeader( 'X-Content-Type-Options', 'nosniff' );
		return $response;
	}

	protected function parseJsonObject(): array {
		$contentType = strtolower( trim( explode( ';', $this->getRequest()->getHeaderLine( 'Content-Type' ) )[0] ) );
		if ( $contentType !== 'application/json' ) {
			throw new CowlenderException(
				415,
				'unsupported_media_type',
				'Write requests must use Content-Type: application/json.'
			);
		}

		$requestBody = $this->getRequest()->getBody();
		if ( is_object( $requestBody ) && method_exists( $requestBody, 'getContents' ) ) {
			$rawBody = $requestBody->getContents();
		} elseif ( is_resource( $requestBody ) ) {
			$rawBody = stream_get_contents( $requestBody );
		} else {
			$rawBody = (string)$requestBody;
		}

		if ( strlen( $rawBody ) > self::MAX_REQUEST_BYTES ) {
			throw new CowlenderException(
				413,
				'request_too_large',
				'The JSON request body is too large.'
			);
		}

		$trimmedBody = ltrim( $rawBody );
		if ( $trimmedBody === '' || $trimmedBody[0] !== '{' ) {
			throw new CowlenderException(
				400,
				'invalid_json',
				'The request body must be a JSON object.'
			);
		}

		try {
			$decoded = json_decode( $rawBody, true, 32, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			throw new CowlenderException(
				400,
				'invalid_json',
				'The request body is not valid JSON.',
				[ 'json' => $exception->getMessage() ]
			);
		}

		if ( !is_array( $decoded ) ) {
			throw new CowlenderException(
				400,
				'invalid_json',
				'The request body must be a JSON object.'
			);
		}

		return $decoded;
	}

	protected function assertRegisteredWithRight( string $right ): UserIdentity {
		$user = $this->getAuthority()->getUser();
		if ( !$user->isRegistered() ) {
			throw new CowlenderException(
				401,
				'authentication_required',
				'You must be logged in to perform this action.'
			);
		}
		$this->assertRight( $right );
		return $user;
	}

	protected function assertRight( string $right ): void {
		if ( !$this->getAuthority()->isAllowed( $right ) ) {
			throw new CowlenderException(
				403,
				'permission_denied',
				'You do not have permission to perform this action.',
				[ 'requiredRight' => $right ]
			);
		}
	}

	protected function assertCanEdit( array $event ): UserIdentity {
		$user = $this->getAuthority()->getUser();
		if ( !$user->isRegistered() ) {
			throw new CowlenderException(
				401,
				'authentication_required',
				'You must be logged in to edit an event.'
			);
		}

		if ( $this->getAuthority()->isAllowed( 'cowlender-edit-all' ) ) {
			return $user;
		}

		if (
			!$this->getAuthority()->isAllowed( 'cowlender-edit-own' )
			|| (int)$event['createdById'] !== $user->getId()
		) {
			throw new CowlenderException(
				403,
				'permission_denied',
				'You may only edit Cowlender events that you created.'
			);
		}

		return $user;
	}

	protected function assertValidCsrfToken( UserIdentity $userIdentity ): void {
		$token = $this->getRequest()->getHeaderLine( 'X-CSRF-Token' );
		$user = MediaWikiServices::getInstance()
			->getUserFactory()
			->newFromUserIdentity( $userIdentity );

		if ( $token === '' || !$user->matchEditToken( $token ) ) {
			throw new CowlenderException(
				403,
				'invalid_csrf_token',
				'A valid MediaWiki CSRF token is required. Send it in X-CSRF-Token.'
			);
		}
	}

	private function problemResponse( CowlenderException $exception ) {
		$body = [
			'type' => 'about:blank',
			'title' => $this->statusTitle( $exception->getHttpStatus() ),
			'status' => $exception->getHttpStatus(),
			'code' => $exception->getErrorCode(),
			'detail' => $exception->getMessage(),
		];
		if ( $exception->getErrors() !== [] ) {
			$body['errors'] = $exception->getErrors();
		}

		return $this->jsonResponse( $body, $exception->getHttpStatus() );
	}

	private function statusTitle( int $status ): string {
		return match ( $status ) {
			400 => 'Bad Request',
			401 => 'Unauthorized',
			403 => 'Forbidden',
			404 => 'Not Found',
			409 => 'Conflict',
			413 => 'Content Too Large',
			415 => 'Unsupported Media Type',
			default => 'Request Failed',
		};
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Cowlender\Domain;

use RuntimeException;

/**
 * An expected domain/API error that can safely be returned to a client.
 */
class CowlenderException extends RuntimeException {
	private int $httpStatus;
	private string $errorCode;
	private array $errors;

	public function __construct(
		int $httpStatus,
		string $errorCode,
		string $message,
		array $errors = []
	) {
		parent::__construct( $message );
		$this->httpStatus = $httpStatus;
		$this->errorCode = $errorCode;
		$this->errors = $errors;
	}

	public function getHttpStatus(): int {
		return $this->httpStatus;
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}

	public function getErrors(): array {
		return $this->errors;
	}
}

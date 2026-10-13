<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Thrown anywhere to stop the request with an HTTP status and a safe message. */
class HttpException extends RuntimeException
{
	public function __construct(
		public readonly int $status,
		string $message = '',
		public readonly array $errors = [],
	) {
		parent::__construct($message ?: self::defaultMessage($status));
	}

	public static function defaultMessage(int $status): string
	{
		return match ($status) {
			400 => 'Bad request.',
			401 => 'Please log in to continue.',
			403 => 'You do not have permission to do that.',
			404 => 'Not found.',
			405 => 'Method not allowed.',
			409 => 'This conflicts with existing data.',
			413 => 'The upload is too large.',
			419 => 'Your session expired. Refresh the page and try again.',
			422 => 'Please fix the highlighted fields.',
			429 => 'Too many attempts. Please wait and try again.',
			default => 'Something went wrong.',
		};
	}

	public static function validation(array $errors, string $message = ''): self
	{
		return new self(422, $message ?: self::defaultMessage(422), $errors);
	}
}

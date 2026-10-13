<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Outgoing mail. The "log" driver (default) appends messages to
 * storage/logs/mail.log so password-reset links can be used in development.
 * The "mail" driver uses PHP's mail(); plug in SMTP here later.
 */
final class Mailer
{
	/** Messages sent during this process (tests read these). */
	public static array $sent = [];

	public static function send(string $to, string $subject, string $body): void
	{
		self::$sent[] = compact('to', 'subject', 'body');
		if (config('mail.driver') === 'mail') {
			$headers = 'From: ' . config('mail.from') . "\r\nContent-Type: text/plain; charset=utf-8";
			mail($to, $subject, $body, $headers);
			return;
		}
		if (config('mail.driver') === 'log') {
			$entry = sprintf("[%s] To: %s\nSubject: %s\n\n%s\n%s\n", gmdate('c'), $to, $subject, $body, str_repeat('-', 60));
			file_put_contents(BASE_PATH . '/storage/logs/mail.log', $entry, FILE_APPEND | LOCK_EX);
		}
	}
}

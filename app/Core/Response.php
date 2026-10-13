<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
	private array $headers = [];
	private ?string $filePath = null;

	public function __construct(
		public string $body = '',
		public int $status = 200,
		array $headers = [],
	) {
		$this->headers = $headers;
	}

	public static function json(mixed $data, int $status = 200): self
	{
		return new self(
			json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
			$status,
			['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
		);
	}

	public static function html(string $html, int $status = 200): self
	{
		return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
	}

	public static function redirect(string $to, int $status = 302): self
	{
		// Only same-site relative redirects.
		if (!str_starts_with($to, '/') || str_starts_with($to, '//')) {
			$to = '/';
		}
		return new self('', $status, ['Location' => $to]);
	}

	public static function download(string $path, string $downloadName, string $mime): self
	{
		$r = new self('', 200, [
			'Content-Type' => $mime,
			'Content-Length' => (string) filesize($path),
			'Content-Disposition' => self::disposition($downloadName),
			'X-Content-Type-Options' => 'nosniff',
			'Cache-Control' => 'private, no-store',
		]);
		$r->filePath = $path;
		return $r;
	}

	public static function csv(string $filename, array $header, iterable $rows): self
	{
		$fh = fopen('php://temp', 'r+');
		fputcsv($fh, $header, ',', '"', '');
		foreach ($rows as $row) {
			// Neutralise spreadsheet formula injection.
			$row = array_map(static function ($v) {
				$v = (string) $v;
				return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
			}, $row);
			fputcsv($fh, $row, ',', '"', '');
		}
		rewind($fh);
		$body = stream_get_contents($fh);
		fclose($fh);
		return new self($body, 200, [
			'Content-Type' => 'text/csv; charset=utf-8',
			'Content-Disposition' => self::disposition($filename),
			'Cache-Control' => 'private, no-store',
		]);
	}

	private static function disposition(string $name): string
	{
		$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'download';
		return sprintf("attachment; filename=\"%s\"; filename*=UTF-8''%s", $ascii, rawurlencode($name));
	}

	public function withHeader(string $name, string $value): self
	{
		$this->headers[$name] = $value;
		return $this;
	}

	public function header(string $name): ?string
	{
		return $this->headers[$name] ?? null;
	}

	public function send(): void
	{
		http_response_code($this->status);
		$defaults = [
			'X-Content-Type-Options' => 'nosniff',
			'X-Frame-Options' => 'DENY',
			'Referrer-Policy' => 'same-origin',
			'Content-Security-Policy' => "default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; "
				. "style-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com; "
				. "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; img-src 'self' data:; "
				. "connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
		];
		foreach ($this->headers + $defaults as $name => $value) {
			header("$name: $value");
		}
		if ($this->filePath !== null) {
			readfile($this->filePath);
			return;
		}
		echo $this->body;
	}
}

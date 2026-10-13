<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
	public array $params = [];
	private ?array $json = null;

	public function __construct(
		public readonly string $method,
		public readonly string $path,
		public readonly array $query,
		public readonly array $post,
		public readonly array $files,
		public readonly array $server,
		private readonly string $rawBody = '',
	) {
	}

	public static function capture(): self
	{
		$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
		$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
		$path = '/' . trim(rawurldecode($path), '/');
		return new self($method, $path, $_GET, $_POST, $_FILES, $_SERVER, (string) file_get_contents('php://input'));
	}

	public function isApi(): bool
	{
		return str_starts_with($this->path, '/api/');
	}

	public function header(string $name): ?string
	{
		$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
		return $this->server[$key] ?? ($name === 'Content-Type' ? ($this->server['CONTENT_TYPE'] ?? null) : null);
	}

	public function ip(): string
	{
		return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
	}

	/** JSON body if sent as JSON, otherwise form fields. */
	public function input(): array
	{
		if ($this->json === null) {
			$type = (string) $this->header('Content-Type');
			if (str_contains($type, 'application/json')) {
				$decoded = $this->rawBody === '' ? [] : json_decode($this->rawBody, true);
				if (!is_array($decoded)) {
					throw new HttpException(400, 'Request body must be a JSON object.');
				}
				$this->json = $decoded;
			} else {
				$this->json = $this->post;
			}
		}
		return $this->json;
	}

	public function get(string $key, mixed $default = null): mixed
	{
		return $this->input()[$key] ?? $this->query[$key] ?? $default;
	}

	public function param(string $name): string
	{
		return $this->params[$name] ?? '';
	}

	public function intParam(string $name): int
	{
		$v = $this->param($name);
		if (!ctype_digit($v)) {
			throw new HttpException(404);
		}
		return (int) $v;
	}

	/** Normalised list of uploaded files for a field (supports name="files[]"). */
	public function uploadedFiles(string $field): array
	{
		$f = $this->files[$field] ?? null;
		if (!$f) {
			return [];
		}
		if (!is_array($f['name'])) {
			return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
		}
		$out = [];
		foreach ($f['name'] as $i => $name) {
			if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) {
				continue;
			}
			$out[] = [
				'name' => $name,
				'type' => $f['type'][$i],
				'tmp_name' => $f['tmp_name'][$i],
				'error' => $f['error'][$i],
				'size' => $f['size'][$i],
			];
		}
		return $out;
	}
}

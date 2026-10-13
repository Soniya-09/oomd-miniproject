<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;

/**
 * Untrusted upload handling: extension allow-list, real MIME sniffing, size
 * limits, random server-side names, storage outside the web root.
 */
final class FileStorage
{
	/** Never accepted, whatever an assignment allows. */
	private const BLOCKED = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'phps', 'pht', 'exe', 'bat', 'cmd', 'com', 'sh', 'js', 'mjs', 'html', 'htm', 'svg', 'xhtml', 'jsp', 'asp', 'aspx', 'cgi', 'pl', 'py', 'msi', 'dll', 'jar', 'vbs', 'ps1', 'htaccess'];

	public static function allowedExtensions(): array
	{
		return array_keys(config('uploads.allowed'));
	}

	/**
	 * Validate and store one $_FILES entry.
	 * @param string[] $allowedExt subset of configured extensions
	 * @return array{original_name:string, stored_name:string, mime_type:string, size_bytes:int}
	 */
	public static function store(array $file, array $allowedExt, int $maxMb, string $field = 'files'): array
	{
		if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			$msg = match ($file['error'] ?? null) {
				UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the server allows.',
				UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.',
				default => 'The file could not be uploaded.',
			};
			throw HttpException::validation([$field => $msg]);
		}
		$original = self::cleanName((string) $file['name']);
		$ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
		$allowedExt = array_values(array_intersect(array_map('strtolower', $allowedExt), self::allowedExtensions()));

		if ($ext === '' || in_array($ext, self::BLOCKED, true) || !in_array($ext, $allowedExt, true)) {
			throw HttpException::validation([$field => "\"$original\" is not an allowed file type. Allowed: " . implode(', ', $allowedExt) . '.']);
		}
		// Double extensions like "report.php.pdf" are rejected outright.
		foreach (array_slice(explode('.', strtolower($original)), 1, -1) as $inner) {
			if (in_array($inner, self::BLOCKED, true)) {
				throw HttpException::validation([$field => "\"$original\" has a suspicious file name."]);
			}
		}
		$size = (int) filesize($file['tmp_name']);
		$limit = min($maxMb, (int) config('uploads.max_mb')) * 1024 * 1024;
		if ($size <= 0) {
			throw HttpException::validation([$field => "\"$original\" is empty."]);
		}
		if ($size > $limit) {
			throw HttpException::validation([$field => "\"$original\" is larger than " . ($limit / 1048576) . ' MB.']);
		}
		$mime = self::sniff($file['tmp_name']);
		if (!in_array($mime, config("uploads.allowed.$ext"), true)) {
			throw HttpException::validation([$field => "\"$original\" does not look like a real .$ext file."]);
		}
		if (self::looksExecutable($file['tmp_name'])) {
			throw HttpException::validation([$field => "\"$original\" contains code and was rejected."]);
		}

		$stored = bin2hex(random_bytes(20));
		$path = self::path($stored);
		if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0770, true) && !is_dir(dirname($path))) {
			throw new \RuntimeException('Cannot create upload directory');
		}
		// move_uploaded_file only works for real HTTP uploads; tests pass plain temp files.
		$moved = is_uploaded_file($file['tmp_name']) ? move_uploaded_file($file['tmp_name'], $path) : rename($file['tmp_name'], $path);
		if (!$moved) {
			throw new \RuntimeException('Cannot store upload');
		}
		@chmod($path, 0640);
		return ['original_name' => $original, 'stored_name' => $stored, 'mime_type' => $mime, 'size_bytes' => $size];
	}

	/** Absolute path for a stored name; the name is validated so it cannot traverse. */
	public static function path(string $stored): string
	{
		if (!preg_match('/^[a-f0-9]{40}$/', $stored)) {
			throw new HttpException(404);
		}
		return rtrim((string) config('uploads.path'), '/\\') . DIRECTORY_SEPARATOR . substr($stored, 0, 2) . DIRECTORY_SEPARATOR . $stored;
	}

	public static function delete(string $stored): void
	{
		$p = self::path($stored);
		if (is_file($p)) {
			@unlink($p);
		}
	}

	private static function sniff(string $path): string
	{
		if (!function_exists('finfo_open')) {
			throw new \RuntimeException('The fileinfo PHP extension is required for uploads.');
		}
		$f = finfo_open(FILEINFO_MIME_TYPE);
		$mime = (string) finfo_file($f, $path);
		finfo_close($f);
		return $mime;
	}

	private static function looksExecutable(string $path): bool
	{
		$head = (string) file_get_contents($path, false, null, 0, 4096);
		return str_starts_with($head, 'MZ') || str_starts_with($head, "\x7FELF") || stripos($head, '<?php') !== false;
	}

	public static function cleanName(string $name): string
	{
		$name = basename(str_replace('\\', '/', $name));
		$name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
		$name = trim($name, " .");
		return mb_substr($name !== '' ? $name : 'file', 0, 200);
	}
}

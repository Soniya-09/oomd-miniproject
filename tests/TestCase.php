<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Database\Seeders\DemoSeeder;

final class AssertionFailed extends \Exception
{
}

/**
 * Base class: each test runs inside a transaction on the test database that is
 * rolled back afterwards, with the demo data seeded once.
 */
abstract class TestCase
{
	protected static Kernel $kernel;
	protected array $ids;
	public int $assertions = 0;

	public function setUp(): void
	{
		self::$kernel ??= new Kernel();
		Session::useArrayStore();
		Auth::forgetCached();
		Database::get()->pdo()->beginTransaction();
		$this->ids = (new DemoSeeder())->run();
	}

	public function tearDown(): void
	{
		$pdo = Database::get()->pdo();
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		Auth::forgetCached();
	}

	// ------------------------------------------------------------------ HTTP

	protected function actingAs(?int $userId): void
	{
		Session::useArrayStore();
		if ($userId !== null) {
			Session::set('user_id', $userId);
		}
		Auth::forgetCached();
	}

	protected function call(string $method, string $path, ?array $body = null, array $files = [], bool $csrf = true): Response
	{
		Auth::forgetCached();
		$query = [];
		if (str_contains($path, '?')) {
			[$path, $qs] = explode('?', $path, 2);
			parse_str($qs, $query);
		}
		$server = ['REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => $method];
		if ($csrf) {
			$server['HTTP_X_CSRF_TOKEN'] = Csrf::token();
		}
		$raw = '';
		$post = [];
		if ($files) {
			$post = $body ?? [];
		} elseif ($body !== null) {
			$server['CONTENT_TYPE'] = 'application/json';
			$raw = json_encode($body);
		}
		$request = new Request($method, $path, $query, $post, $files, $server, $raw);
		return self::$kernel->handle($request);
	}

	protected function get(string $path): Response
	{
		return $this->call('GET', $path);
	}

	protected function json(Response $r): array
	{
		return json_decode($r->body, true) ?? [];
	}

	/** Build a $_FILES-style entry from a temp file with given content. */
	protected function fakeUpload(string $name, string $content): array
	{
		$tmp = tempnam(sys_get_temp_dir(), 'eft');
		file_put_contents($tmp, $content);
		return ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
	}

	protected function pdf(): string
	{
		return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
	}

	// ------------------------------------------------------------------ assertions

	protected function assertTrue(mixed $v, string $msg = 'Expected true'): void
	{
		$this->assertions++;
		if ($v !== true) {
			throw new AssertionFailed($msg . ' (got ' . var_export($v, true) . ')');
		}
	}

	protected function assertFalse(mixed $v, string $msg = 'Expected false'): void
	{
		$this->assertTrue($v === false, $msg);
	}

	protected function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
	{
		$this->assertions++;
		if ($expected !== $actual) {
			throw new AssertionFailed(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
		}
	}

	protected function assertStatus(int $status, Response $r, string $msg = ''): void
	{
		$this->assertions++;
		if ($r->status !== $status) {
			throw new AssertionFailed(($msg ? "$msg: " : '') . "expected HTTP $status, got {$r->status}: " . substr($r->body, 0, 300));
		}
	}

	protected function assertCount(int $n, array $list, string $msg = ''): void
	{
		$this->assertSame($n, count($list), $msg ?: 'count');
	}
}

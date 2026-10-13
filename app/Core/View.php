<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Plain-PHP templates in resources/views. A view may set $layout to wrap
 * itself in resources/views/layouts/<name>.php, which receives $content.
 */
final class View
{
	public static function render(string $view, array $data = []): string
	{
		$file = BASE_PATH . '/resources/views/' . str_replace('.', '/', $view) . '.php';
		if (!is_file($file)) {
			throw new \RuntimeException("View not found: $view");
		}
		[$content, $vars] = self::capture($file, $data);
		if (!empty($vars['layout'])) {
			// Views may set $scripts / $charts for the layout to include.
			$pass = array_intersect_key($vars, array_flip(['scripts', 'charts']));
			return self::render('layouts.' . $vars['layout'], ['content' => $content] + $pass + $data);
		}
		return $content;
	}

	public static function partial(string $view, array $data = []): string
	{
		return self::render($view, $data);
	}

	private static function capture(string $__file, array $__data): array
	{
		extract($__data, EXTR_SKIP);
		$layout = null;
		ob_start();
		try {
			require $__file;
		} catch (\Throwable $e) {
			ob_end_clean();
			throw $e;
		}
		$__content = (string) ob_get_clean();
		return [$__content, ['layout' => $layout, 'scripts' => $scripts ?? null, 'charts' => $charts ?? null]];
	}

	public static function page(string $view, array $data = [], int $status = 200): Response
	{
		return Response::html(self::render($view, $data), $status);
	}
}

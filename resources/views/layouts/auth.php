<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= e($title ?? 'EduFlow') ?> · EduFlow</title>
	<link rel="icon" href="/favicon.svg" type="image/svg+xml">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="auth-body">
	<main class="auth-wrap">
		<div class="auth-brand">
			<?= App\Core\View::partial('components.logo') ?>
			<span>EduFlow</span>
		</div>
		<div class="card auth-card">
			<div class="card-body p-4">
				<?= $content ?>
			</div>
		</div>
		<p class="text-center text-muted small mt-3">Academic management &amp; productivity portal</p>
	</main>
</body>
</html>

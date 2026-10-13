<?php
/** @var array $user @var string $content @var string $title */
$flashError = App\Core\Session::flash('error');
$flashNotice = App\Core\Session::flash('notice');
$initials = implode('', array_map(fn($p) => mb_strtoupper(mb_substr($p, 0, 1)), array_slice(preg_split('/\s+/', trim($user['full_name'])), 0, 2)));
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
	<meta name="app-timezone" content="<?= e($user['timezone']) ?>">
	<meta name="app-role" content="<?= e($user['role']) ?>">
	<title><?= e($title) ?> · EduFlow</title>
	<link rel="icon" href="/favicon.svg" type="image/svg+xml">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
	<a class="visually-hidden-focusable skip-link" href="#main">Skip to content</a>
	<div class="app-shell">
		<aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar" aria-label="Sidebar">
			<div class="sidebar-brand">
				<?= App\Core\View::partial('components.logo') ?>
				<span>EduFlow</span>
				<button type="button" class="btn-close btn-close-white d-lg-none ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close menu"></button>
			</div>
			<div class="sidebar-institution text-truncate" title="<?= e($user['institution_name']) ?>"><?= e($user['institution_name']) ?></div>
			<?= App\Core\View::partial('components.nav', ['user' => $user, 'path' => $path ?? '']) ?>
		</aside>

		<div class="app-main">
			<header class="topbar">
				<button class="btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Open menu">
					<i class="bi bi-list"></i>
				</button>
				<h1 class="page-title"><?= e($title) ?></h1>
				<div class="topbar-actions">
					<?php if ($user['role'] !== 'admin'): ?>
						<a class="btn btn-icon position-relative" href="/notifications" aria-label="Notifications" id="notif-bell">
							<i class="bi bi-bell"></i>
							<span class="badge rounded-pill bg-danger notif-count d-none" id="notif-count"></span>
						</a>
					<?php endif; ?>
					<div class="dropdown">
						<button class="btn btn-profile dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
							<span class="avatar" aria-hidden="true"><?= e($initials) ?></span>
							<span class="d-none d-md-inline"><?= e($user['full_name']) ?></span>
						</button>
						<ul class="dropdown-menu dropdown-menu-end">
							<li><span class="dropdown-item-text small text-muted"><?= e($user['email']) ?><br><span class="text-capitalize"><?= e($user['role']) ?></span></span></li>
							<li><hr class="dropdown-divider"></li>
							<li><a class="dropdown-item" href="/profile"><i class="bi bi-person me-2"></i>Profile</a></li>
							<li>
								<form method="post" action="/logout">
									<?= csrf_field() ?>
									<button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Log out</button>
								</form>
							</li>
						</ul>
					</div>
				</div>
			</header>

			<main id="main" class="app-content" tabindex="-1">
				<?php if ($flashError): ?><div class="alert alert-danger"><?= e($flashError) ?></div><?php endif; ?>
				<?php if ($flashNotice): ?><div class="alert alert-success"><?= e($flashNotice) ?></div><?php endif; ?>
				<?= $content ?>
			</main>
		</div>
	</div>

	<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toasts" aria-live="polite" aria-atomic="false"></div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
	<?php if (!empty($charts)): ?>
		<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
	<?php endif; ?>
	<script src="<?= e(asset('js/app.js')) ?>"></script>
	<?php foreach ($scripts ?? [] as $script): ?>
		<script src="<?= e(asset('js/' . $script)) ?>"></script>
	<?php endforeach; ?>
</body>
</html>

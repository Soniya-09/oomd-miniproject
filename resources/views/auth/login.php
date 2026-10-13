<?php $layout = 'auth'; ?>
<h1 class="h4 mb-1">Welcome back</h1>
<p class="text-muted mb-4">Log in with your institution account.</p>

<?php if (!empty($notice)): ?><div class="alert alert-success" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>

<form method="post" action="/login" novalidate>
	<?= csrf_field() ?>
	<input type="hidden" name="next" value="<?= e($next ?? '') ?>">
	<div class="mb-3">
		<label class="form-label" for="email">Email</label>
		<input class="form-control" id="email" name="email" type="email" autocomplete="username" required autofocus value="<?= e($email ?? '') ?>">
	</div>
	<div class="mb-2">
		<label class="form-label" for="password">Password</label>
		<input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
	</div>
	<div class="text-end mb-3">
		<a class="small" href="/forgot-password">Forgot password?</a>
	</div>
	<button class="btn btn-primary w-100" type="submit">Log in</button>
</form>

<?php $layout = 'auth'; ?>
<h1 class="h4 mb-1">Choose a new password</h1>

<?php if (empty($valid)): ?>
	<div class="alert alert-warning mt-3" role="alert">This reset link is invalid or has expired.</div>
	<a class="btn btn-primary w-100" href="/forgot-password">Request a new link</a>
<?php else: ?>
	<p class="text-muted mb-4">At least 8 characters, with a letter and a number.</p>
	<?php if (!empty($error)): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
	<form method="post" action="/reset-password" novalidate>
		<?= csrf_field() ?>
		<input type="hidden" name="token" value="<?= e($token) ?>">
		<div class="mb-3">
			<label class="form-label" for="password">New password</label>
			<input class="form-control" id="password" name="password" type="password" autocomplete="new-password" required minlength="8" autofocus>
		</div>
		<div class="mb-3">
			<label class="form-label" for="password_confirmation">Confirm new password</label>
			<input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="8">
		</div>
		<button class="btn btn-primary w-100" type="submit">Update password</button>
	</form>
<?php endif; ?>

<?php $layout = 'auth'; ?>
<h1 class="h4 mb-1">Forgot your password?</h1>
<p class="text-muted mb-4">Enter your account email and we will send you a link to choose a new one.</p>

<?php if (!empty($error)): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>

<form method="post" action="/forgot-password" novalidate>
	<?= csrf_field() ?>
	<div class="mb-3">
		<label class="form-label" for="email">Email</label>
		<input class="form-control" id="email" name="email" type="email" autocomplete="username" required autofocus value="<?= e($email ?? '') ?>">
	</div>
	<button class="btn btn-primary w-100" type="submit">Send reset link</button>
</form>
<p class="text-center mt-3 mb-0"><a class="small" href="/login">Back to log in</a></p>

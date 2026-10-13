<?php $layout = 'app'; $scripts = ['profile.js']; ?>
<div class="row g-3" data-page="profile">
	<div class="col-lg-6">
		<div class="card">
			<div class="card-header">Your details</div>
			<div class="card-body">
				<form id="profile-form" novalidate>
					<div class="alert alert-danger d-none" data-form-error role="alert"></div>
					<div class="mb-3">
						<label class="form-label" for="p-name">Full name</label>
						<input class="form-control" id="p-name" name="full_name" value="<?= e($user['full_name']) ?>" required maxlength="150">
						<div class="invalid-feedback" data-error-for="full_name"></div>
					</div>
					<div class="mb-3">
						<label class="form-label" for="p-phone">Phone</label>
						<input class="form-control" id="p-phone" name="phone" value="<?= e($user['phone'] ?? '') ?>" maxlength="30" inputmode="tel">
						<div class="invalid-feedback" data-error-for="phone"></div>
					</div>
					<dl class="row small text-muted mb-3">
						<dt class="col-4">Email</dt><dd class="col-8"><?= e($user['email']) ?></dd>
						<dt class="col-4">Role</dt><dd class="col-8 text-capitalize"><?= e($user['role']) ?></dd>
						<dt class="col-4">Institution</dt><dd class="col-8"><?= e($user['institution_name']) ?></dd>
						<dt class="col-4">Time zone</dt><dd class="col-8"><?= e($user['timezone']) ?></dd>
					</dl>
					<p class="form-text">Email and role are managed by your administrator.</p>
					<button class="btn btn-primary" type="submit">Save changes</button>
				</form>
			</div>
		</div>
	</div>
	<div class="col-lg-6">
		<div class="card">
			<div class="card-header">Change password</div>
			<div class="card-body">
				<form id="password-form" novalidate>
					<div class="alert alert-danger d-none" data-form-error role="alert"></div>
					<div class="mb-3">
						<label class="form-label" for="pw-current">Current password</label>
						<input class="form-control" id="pw-current" name="current_password" type="password" autocomplete="current-password" required>
						<div class="invalid-feedback" data-error-for="current_password"></div>
					</div>
					<div class="mb-3">
						<label class="form-label" for="pw-new">New password</label>
						<input class="form-control" id="pw-new" name="new_password" type="password" autocomplete="new-password" required minlength="8">
						<div class="form-text">At least 8 characters, with a letter and a number.</div>
						<div class="invalid-feedback" data-error-for="new_password"></div>
					</div>
					<div class="mb-3">
						<label class="form-label" for="pw-confirm">Confirm new password</label>
						<input class="form-control" id="pw-confirm" name="confirm_password" type="password" autocomplete="new-password" required>
						<div class="invalid-feedback" data-error-for="confirm_password"></div>
					</div>
					<button class="btn btn-primary" type="submit">Update password</button>
				</form>
			</div>
		</div>
	</div>
</div>

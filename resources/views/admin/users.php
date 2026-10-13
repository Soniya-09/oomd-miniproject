<?php
$layout = 'app';
$scripts = ['admin.js'];
$roleOptions = ['' => 'All roles', 'admin' => 'Admins', 'teacher' => 'Teachers', 'student' => 'Students'];
ob_start(); ?>
	<label class="visually-hidden" for="user-search">Search users</label>
	<input class="form-control" id="user-search" type="search" placeholder="Search name, email, ID…" data-filter="q">
	<?php if ($roleFilter === ''): ?>
		<label class="visually-hidden" for="user-role">Role</label>
		<select class="form-select" id="user-role" data-filter="role">
			<?php foreach ($roleOptions as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?>
		</select>
	<?php endif; ?>
	<label class="visually-hidden" for="user-active">Status</label>
	<select class="form-select" id="user-active" data-filter="active">
		<option value="">Any status</option><option value="1">Active</option><option value="0">Deactivated</option>
	</select>
	<?php if ($roleFilter === 'student'): ?>
		<label class="visually-hidden" for="user-class">Class</label>
		<select class="form-select" id="user-class" data-filter="class_id"><option value="">All classes</option></select>
	<?php endif; ?>
	<div class="ms-auto d-flex gap-2">
		<button class="btn btn-outline-primary" type="button" data-action="import"><i class="bi bi-upload me-1"></i>Import CSV</button>
		<button class="btn btn-primary" type="button" data-action="create"><i class="bi bi-plus-lg me-1"></i>Add <?= e($roleFilter ?: 'user') ?></button>
	</div>
<?php $toolbar = ob_get_clean(); ?>
<div data-page="admin-users" data-role="<?= e($roleFilter) ?>">
	<?= App\Core\View::partial('components.table', ['id' => 'users-table', 'toolbar' => $toolbar]) ?>
</div>

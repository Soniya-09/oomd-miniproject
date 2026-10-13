<?php $layout = 'app'; $scripts = ['shared.js']; ?>
<div data-page="announcements" data-can-post="<?= in_array($user['role'], ['admin', 'teacher'], true) ? '1' : '0' ?>">
	<div class="card">
		<div class="card-header">
			Announcements
			<div class="card-actions">
				<label class="visually-hidden" for="an-search">Search announcements</label>
				<input class="form-control form-control-sm" id="an-search" type="search" placeholder="Search…">
				<?php if ($user['role'] !== 'student'): ?>
					<button class="btn btn-primary btn-sm" type="button" data-action="create"><i class="bi bi-megaphone me-1"></i>New announcement</button>
				<?php endif; ?>
			</div>
		</div>
		<div id="announcement-list"></div>
		<div class="table-footer" id="announcement-footer"></div>
	</div>
</div>

<?php $layout = 'app'; $scripts = ['admin.js']; ?>
<div data-page="admin-classes">
	<div class="card">
		<div class="card-header">
			Classes
			<div class="card-actions">
				<label class="visually-hidden" for="class-session">Academic session</label>
				<select class="form-select form-select-sm" id="class-session"><option value="">All sessions</option></select>
				<button class="btn btn-primary btn-sm" type="button" data-action="create"><i class="bi bi-plus-lg me-1"></i>Add class</button>
			</div>
		</div>
		<div class="table-responsive">
			<table class="table table-hover align-middle">
				<thead><tr><th>Class</th><th>Session</th><th>Department</th><th class="text-end">Students</th><th class="text-end">Subjects taught</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
				<tbody id="classes-body"></tbody>
			</table>
		</div>
	</div>
</div>

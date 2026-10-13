<?php $layout = 'app'; $scripts = ['admin.js']; ?>
<div data-page="admin-subjects">
	<div class="card">
		<div class="card-header">
			Subjects
			<div class="card-actions">
				<label class="visually-hidden" for="subject-search">Search subjects</label>
				<input class="form-control form-control-sm" id="subject-search" type="search" placeholder="Search…">
				<button class="btn btn-primary btn-sm" type="button" data-action="create"><i class="bi bi-plus-lg me-1"></i>Add subject</button>
			</div>
		</div>
		<div class="table-responsive">
			<table class="table table-hover align-middle">
				<thead><tr><th>Code</th><th>Name</th><th>Department</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
				<tbody id="subjects-body"></tbody>
			</table>
		</div>
	</div>
</div>

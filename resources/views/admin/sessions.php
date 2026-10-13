<?php $layout = 'app'; $scripts = ['admin.js']; ?>
<div data-page="admin-sessions" class="row g-3">
	<div class="col-lg-7">
		<div class="card">
			<div class="card-header">
				Academic sessions
				<div class="card-actions"><button class="btn btn-primary btn-sm" type="button" data-action="create-session"><i class="bi bi-plus-lg me-1"></i>Add session</button></div>
			</div>
			<div class="table-responsive">
				<table class="table align-middle">
					<thead><tr><th>Name</th><th>Starts</th><th>Ends</th><th class="text-end">Classes</th><th></th><th class="text-end">Actions</th></tr></thead>
					<tbody id="sessions-body"></tbody>
				</table>
			</div>
		</div>
	</div>
	<div class="col-lg-5">
		<div class="card">
			<div class="card-header">
				Departments
				<div class="card-actions"><button class="btn btn-primary btn-sm" type="button" data-action="create-department"><i class="bi bi-plus-lg me-1"></i>Add department</button></div>
			</div>
			<div class="table-responsive">
				<table class="table align-middle">
					<thead><tr><th>Code</th><th>Name</th><th class="text-end">Actions</th></tr></thead>
					<tbody id="departments-body"></tbody>
				</table>
			</div>
		</div>
	</div>
</div>

<?php $layout = 'app'; $scripts = ['admin.js']; ?>
<div data-page="admin-settings" class="row">
	<div class="col-lg-7">
		<div class="card">
			<div class="card-header">Institution</div>
			<div class="card-body">
				<form id="settings-form" novalidate>
					<div class="alert alert-danger d-none" data-form-error role="alert"></div>
					<div class="mb-3">
						<label class="form-label" for="st-name">Name</label>
						<input class="form-control" id="st-name" name="name" maxlength="190" required>
						<div class="invalid-feedback" data-error-for="name"></div>
					</div>
					<div class="row g-3 mb-3">
						<div class="col-md-4">
							<label class="form-label" for="st-kind">Type</label>
							<select class="form-select" id="st-kind" name="kind"><option value="college">College</option><option value="school">School</option></select>
						</div>
						<div class="col-md-8">
							<label class="form-label" for="st-tz">Time zone</label>
							<select class="form-select" id="st-tz" name="timezone"></select>
							<div class="form-text">All times are stored in UTC and shown in this zone.</div>
							<div class="invalid-feedback" data-error-for="timezone"></div>
						</div>
					</div>
					<div class="mb-3">
						<label class="form-label" for="st-due">"Due soon" reminder window (hours)</label>
						<input class="form-control" id="st-due" name="due_soon_hours" type="number" min="1" max="168">
						<div class="form-text">Students get one reminder for unsubmitted work due within this window.</div>
						<div class="invalid-feedback" data-error-for="due_soon_hours"></div>
					</div>
					<p class="small text-muted">Institution code: <span id="st-code"></span></p>
					<button class="btn btn-primary" type="submit">Save settings</button>
				</form>
			</div>
		</div>
	</div>
</div>

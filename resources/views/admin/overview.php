<?php $layout = 'app'; $scripts = ['charts.js', 'admin.js']; $charts = true; ?>
<div data-page="admin-overview">
	<div class="stat-grid" id="admin-stats" aria-live="polite">
		<div class="loading-state">Loading…</div>
	</div>

	<div class="row g-3">
		<div class="col-xl-8">
			<div class="card h-100">
				<div class="card-header">Submission trend <span class="text-muted fw-normal small">· last 8 weeks</span></div>
				<div class="card-body"><div class="chart-box" id="chart-trend"><canvas aria-label="Submissions per week" role="img"></canvas></div></div>
			</div>
		</div>
		<div class="col-xl-4">
			<div class="card h-100">
				<div class="card-header">Students per class</div>
				<div class="card-body"><div class="chart-box" id="chart-enrolment"><canvas aria-label="Student enrolment per class" role="img"></canvas></div></div>
			</div>
		</div>
		<div class="col-xl-6">
			<div class="card h-100">
				<div class="card-header">Class-wise assignment completion</div>
				<div class="card-body"><div class="chart-box" id="chart-class"><canvas aria-label="Completion rate by class" role="img"></canvas></div></div>
			</div>
		</div>
		<div class="col-xl-6">
			<div class="card h-100">
				<div class="card-header">Subject-wise assignment activity</div>
				<div class="card-body"><div class="chart-box" id="chart-subject"><canvas aria-label="Assignments and submissions by subject" role="img"></canvas></div></div>
			</div>
		</div>
	</div>
</div>

<?php $layout = 'app'; $scripts = ['charts.js', 'dashboard.js']; $charts = true; ?>
<div data-page="teacher-dashboard">
	<div class="stat-grid" id="teacher-stats" aria-live="polite"><div class="loading-state">Loading…</div></div>
	<div class="row g-3">
		<div class="col-xl-6">
			<div class="card h-100">
				<div class="card-header">Approaching deadlines <a class="small ms-auto" href="/teacher/assignments?status=published">All assignments</a></div>
				<ul class="task-list" id="teacher-upcoming"></ul>
			</div>
		</div>
		<div class="col-xl-6">
			<div class="card h-100">
				<div class="card-header">Submission rate by assignment <a class="small ms-auto" href="/teacher/analytics">Analytics</a></div>
				<div class="card-body"><div class="chart-box" id="chart-rate"><canvas role="img" aria-label="Submission rate by assignment"></canvas></div></div>
			</div>
		</div>
	</div>
</div>

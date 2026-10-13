<?php $layout = 'app'; $scripts = ['charts.js', 'dashboard.js']; $charts = true; ?>
<div data-page="teacher-analytics">
	<div class="card mb-3"><div class="card-body d-flex flex-wrap gap-2 align-items-end">
		<div><label class="form-label small mb-1" for="ta-class">Class</label>
			<select class="form-select form-select-sm" id="ta-class"><option value="">All my classes</option></select></div>
		<div class="ms-auto d-flex flex-wrap gap-2">
			<a class="btn btn-sm btn-outline-primary" data-export="submissions" href="/api/reports/submissions.csv"><i class="bi bi-download me-1"></i>Submissions CSV</a>
			<a class="btn btn-sm btn-outline-primary" data-export="missing" href="/api/reports/missing.csv"><i class="bi bi-download me-1"></i>Missing CSV</a>
			<a class="btn btn-sm btn-outline-primary" data-export="grades" href="/api/reports/grades.csv"><i class="bi bi-download me-1"></i>Grades CSV</a>
		</div>
	</div></div>
	<div class="row g-3">
		<div class="col-xl-8"><div class="card h-100"><div class="card-header">Submitted vs pending by assignment</div>
			<div class="card-body"><div class="chart-box" id="chart-assignments"><canvas role="img" aria-label="Submitted, missing and pending students per assignment"></canvas></div></div></div></div>
		<div class="col-xl-4"><div class="card h-100"><div class="card-header">On time vs late</div>
			<div class="card-body"><div class="chart-box" id="chart-timeliness"><canvas role="img" aria-label="On-time, late and missing submissions"></canvas></div></div></div></div>
		<div class="col-xl-6"><div class="card h-100"><div class="card-header">Average marks (graded work)</div>
			<div class="card-body"><div class="chart-box" id="chart-marks"><canvas role="img" aria-label="Average marks per assignment"></canvas></div></div></div></div>
		<div class="col-xl-6"><div class="card h-100"><div class="card-header">Class completion rate</div>
			<div class="card-body"><div class="chart-box" id="chart-classes"><canvas role="img" aria-label="Completion rate per class"></canvas></div></div></div></div>
		<div class="col-12"><div class="card"><div class="card-header">Students with two or more missing submissions</div>
			<div id="repeat-missing"></div>
			<div class="card-body pt-0 small text-muted">A starting point for a check-in. Missing work can have many causes.</div></div></div>
	</div>
</div>

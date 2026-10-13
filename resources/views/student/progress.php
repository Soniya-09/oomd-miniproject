<?php $layout = 'app'; $scripts = ['charts.js', 'progress.js']; $charts = true; ?>
<div data-page="student-progress">
	<div class="card mb-3">
		<div class="card-body d-flex flex-wrap gap-2 align-items-end" id="progress-filters">
			<div>
				<label class="form-label small mb-1" for="pf-period">Period</label>
				<select class="form-select form-select-sm" id="pf-period">
					<option value="today">Today</option><option value="week">This week</option><option value="month" selected>This month</option><option value="custom">Custom range</option>
				</select>
			</div>
			<div class="d-none" data-custom><label class="form-label small mb-1" for="pf-from">From</label><input class="form-control form-control-sm" type="date" id="pf-from"></div>
			<div class="d-none" data-custom><label class="form-label small mb-1" for="pf-to">To</label><input class="form-control form-control-sm" type="date" id="pf-to"></div>
			<div>
				<label class="form-label small mb-1" for="pf-kind">Tasks</label>
				<select class="form-select form-select-sm" id="pf-kind"><option value="all">All tasks</option><option value="academic">Academic only</option><option value="personal">Personal only</option></select>
			</div>
			<div>
				<span class="form-label small mb-1 d-block" id="pf-gran-label">Trend by</span>
				<div class="btn-group btn-group-sm" role="group" aria-labelledby="pf-gran-label">
					<input type="radio" class="btn-check" name="gran" id="g-day" value="day" checked><label class="btn btn-outline-primary" for="g-day">Day</label>
					<input type="radio" class="btn-check" name="gran" id="g-week" value="week"><label class="btn btn-outline-primary" for="g-week">Week</label>
					<input type="radio" class="btn-check" name="gran" id="g-month" value="month"><label class="btn btn-outline-primary" for="g-month">Month</label>
				</div>
			</div>
			<div class="ms-auto small text-muted" id="pf-range" aria-live="polite"></div>
		</div>
	</div>

	<div class="stat-grid" id="progress-metrics"></div>

	<div class="row g-3">
		<div class="col-xl-8"><div class="card h-100"><div class="card-header">Completed tasks over time</div>
			<div class="card-body"><div class="chart-box" id="chart-trend"><canvas role="img" aria-label="Completed tasks over time"></canvas></div></div></div></div>
		<div class="col-xl-4"><div class="card h-100"><div class="card-header">Task status</div>
			<div class="card-body"><div class="chart-box" id="chart-status"><canvas role="img" aria-label="Task status breakdown"></canvas></div></div></div></div>
		<div class="col-xl-6"><div class="card h-100"><div class="card-header">Academic vs personal (completed)</div>
			<div class="card-body"><div class="chart-box" id="chart-kind"><canvas role="img" aria-label="Academic versus personal completed tasks"></canvas></div></div></div></div>
		<div class="col-xl-6"><div class="card h-100"><div class="card-header">Subject-wise progress</div>
			<div class="card-body"><div class="chart-box" id="chart-subject"><canvas role="img" aria-label="Completion rate by subject"></canvas></div></div></div></div>
		<div class="col-xl-6"><div class="card h-100"><div class="card-header">Assignment submissions</div>
			<div class="card-body"><div class="chart-box chart-sm" id="chart-submission"><canvas role="img" aria-label="On-time, late and missing submissions"></canvas></div></div></div></div>
		<div class="col-xl-6"><div class="card h-100"><div class="card-header">Insights</div>
			<div class="card-body"><ul class="insight-list" id="insights"></ul>
				<p class="small text-muted mt-3 mb-0">These numbers describe activity recorded in EduFlow only. They are not a judgement of effort or ability.</p></div></div></div>
	</div>
</div>

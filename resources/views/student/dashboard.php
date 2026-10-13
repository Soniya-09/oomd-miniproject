<?php $layout = 'app'; $scripts = ['tasks.js', 'charts.js', 'dashboard.js']; $charts = true; ?>
<div data-page="student-dashboard">
	<div class="stat-grid" id="student-stats" aria-live="polite"><div class="loading-state">Loading…</div></div>

	<div class="row g-3">
		<div class="col-xl-7">
			<div class="card mb-3">
				<div class="card-header">Quick add
					<div class="card-actions">
						<button class="btn btn-sm btn-light" type="button" data-action="new-task"><i class="bi bi-plus-lg me-1"></i>Detailed task</button>
						<a class="btn btn-sm btn-light" href="/student/calendar"><i class="bi bi-calendar3 me-1"></i>Calendar</a>
					</div>
				</div>
				<div class="card-body">
					<form id="smart-add" class="smart-add" novalidate>
						<label class="visually-hidden" for="smart-input">Describe a personal task</label>
						<input class="form-control" id="smart-input" placeholder='e.g. "Gym tomorrow 7am for 1 hour high priority @gym"' autocomplete="off" maxlength="500">
						<button class="btn btn-primary" type="submit">Add</button>
					</form>
					<div class="smart-preview" id="smart-preview" aria-live="polite"></div>
				</div>
			</div>

			<div class="card">
				<div class="card-header">Upcoming deadlines <a class="small ms-auto" href="/student/tasks?status=open">All open work</a></div>
				<ul class="task-list" id="upcoming"><li class="loading-state">Loading…</li></ul>
			</div>
		</div>
		<div class="col-xl-5">
			<div class="card mb-3">
				<div class="card-header">Suggested next</div>
				<div class="card-body" id="suggestion"><div class="loading-state">Loading…</div></div>
			</div>
			<div class="card mb-3">
				<div class="card-header">This week</div>
				<div class="card-body"><ul class="insight-list" id="insights"></ul></div>
			</div>
			<div class="card">
				<div class="card-header">Task status <a class="small ms-auto" href="/student/progress">Full progress</a></div>
				<div class="card-body"><div class="chart-box chart-sm" id="chart-status"><canvas role="img" aria-label="Task status breakdown"></canvas></div></div>
			</div>
		</div>
	</div>
</div>

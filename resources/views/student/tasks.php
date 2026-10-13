<?php $layout = 'app'; $scripts = ['tasks.js']; ?>
<div data-page="student-tasks">
	<div class="card mb-3">
		<div class="card-body">
			<form id="smart-add" class="smart-add" novalidate>
				<label class="visually-hidden" for="smart-input">Describe a personal task</label>
				<input class="form-control" id="smart-input" placeholder='Quick add: "Revise DBMS notes by friday 6pm !!"' autocomplete="off" maxlength="500">
				<button class="btn btn-primary" type="submit">Add</button>
				<button class="btn btn-light" type="button" data-action="new-task" title="Add with all fields">Details…</button>
			</form>
			<div class="smart-preview" id="smart-preview" aria-live="polite"></div>
		</div>
	</div>

	<div class="card">
		<div class="table-toolbar">
			<div class="btn-group btn-group-sm" role="group" aria-label="Show">
				<input type="radio" class="btn-check" name="kind" id="k-all" value="" checked><label class="btn btn-outline-primary" for="k-all">All work</label>
				<input type="radio" class="btn-check" name="kind" id="k-academic" value="academic"><label class="btn btn-outline-primary" for="k-academic">Academic</label>
				<input type="radio" class="btn-check" name="kind" id="k-personal" value="personal"><label class="btn btn-outline-primary" for="k-personal">Personal</label>
			</div>
			<label class="visually-hidden" for="t-search">Search</label>
			<input class="form-control form-control-sm" id="t-search" type="search" placeholder="Search…" data-f="q">
			<label class="visually-hidden" for="t-status">Status</label>
			<select class="form-select form-select-sm" id="t-status" data-f="status">
				<option value="">Any status</option><option value="open">Open</option><option value="pending">Pending</option><option value="in_progress">In progress</option>
				<option value="overdue">Overdue</option><option value="due_soon">Due soon (academic)</option><option value="completed">Completed</option>
			</select>
			<label class="visually-hidden" for="t-priority">Priority</label>
			<select class="form-select form-select-sm" id="t-priority" data-f="priority">
				<option value="">Any priority</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option>
			</select>
			<label class="visually-hidden" for="t-subject">Subject</label>
			<select class="form-select form-select-sm" id="t-subject" data-f="subject_id"><option value="">All subjects</option></select>
			<button class="btn btn-sm btn-light ms-auto" type="button" data-action="categories"><i class="bi bi-tags me-1"></i>Categories</button>
		</div>
		<ul class="task-list" id="task-list"></ul>
		<div class="table-footer" id="task-footer"></div>
	</div>
</div>

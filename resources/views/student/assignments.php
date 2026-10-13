<?php
$layout = 'app';
$scripts = ['student.js'];
ob_start(); ?>
	<label class="visually-hidden" for="sa-search">Search assignments</label>
	<input class="form-control" id="sa-search" type="search" placeholder="Search titles…" data-filter="q">
	<label class="visually-hidden" for="sa-subject">Subject</label>
	<select class="form-select" id="sa-subject" data-filter="subject_id"><option value="">All subjects</option></select>
	<label class="visually-hidden" for="sa-status">Status</label>
	<select class="form-select" id="sa-status" data-filter="status">
		<option value="">Any status</option>
		<option value="open">To do</option>
		<option value="pending">Not submitted</option>
		<option value="missing">Missing (overdue)</option>
		<option value="done">Submitted</option>
		<option value="returned">Returned for revision</option>
		<option value="graded">Graded</option>
	</select>
<?php $toolbar = ob_get_clean(); ?>
<div data-page="student-assignments">
	<?= App\Core\View::partial('components.table', ['id' => 'student-assignments-table', 'toolbar' => $toolbar]) ?>
</div>

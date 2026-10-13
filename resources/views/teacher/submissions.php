<?php
$layout = 'app';
$scripts = ['teacher.js'];
ob_start(); ?>
	<label class="visually-hidden" for="s-search">Search</label>
	<input class="form-control" id="s-search" type="search" placeholder="Student or assignment…" data-filter="q">
	<label class="visually-hidden" for="s-status">Status</label>
	<select class="form-select" id="s-status" data-filter="status">
		<option value="to_review">Awaiting review</option>
		<option value="submitted">Submitted</option>
		<option value="resubmitted">Resubmitted</option>
		<option value="under_review">Under review</option>
		<option value="graded">Graded</option>
		<option value="returned">Returned for revision</option>
	</select>
<?php $toolbar = ob_get_clean(); ?>
<div data-page="teacher-submissions">
	<?= App\Core\View::partial('components.table', ['id' => 'queue-table', 'toolbar' => $toolbar]) ?>
</div>

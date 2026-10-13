<?php
$layout = 'app';
$scripts = ['teacher.js'];
ob_start(); ?>
	<label class="visually-hidden" for="a-search">Search assignments</label>
	<input class="form-control" id="a-search" type="search" placeholder="Search titles…" data-filter="q">
	<label class="visually-hidden" for="a-class">Class and subject</label>
	<select class="form-select" id="a-class" data-filter="teaching"><option value="">All my classes</option></select>
	<label class="visually-hidden" for="a-status">Status</label>
	<select class="form-select" id="a-status" data-filter="status">
		<option value="">Any status</option><option value="draft">Draft</option><option value="published">Published</option><option value="closed">Closed</option><option value="cancelled">Cancelled</option>
	</select>
	<button class="btn btn-primary ms-auto" type="button" data-action="create"><i class="bi bi-plus-lg me-1"></i>New assignment</button>
<?php $toolbar = ob_get_clean(); ?>
<div data-page="teacher-assignments">
	<?= App\Core\View::partial('components.table', ['id' => 'assignments-table', 'toolbar' => $toolbar]) ?>
</div>

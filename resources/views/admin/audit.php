<?php
$layout = 'app';
$scripts = ['admin.js'];
ob_start(); ?>
	<label class="visually-hidden" for="audit-action">Filter by action</label>
	<input class="form-control" id="audit-action" type="search" placeholder="Filter by action, e.g. user." data-filter="action">
<?php $toolbar = ob_get_clean(); ?>
<div data-page="admin-audit">
	<?= App\Core\View::partial('components.table', ['id' => 'audit-table', 'toolbar' => $toolbar]) ?>
</div>

<?php
/**
 * Server-paginated table card wired up by EF.Table.
 * @var string $id  @var string $toolbar (trusted HTML)  @var string $header (trusted HTML, optional)
 */
?>
<div class="card" id="<?= e($id) ?>">
	<?php if (!empty($header)): ?><div class="card-header"><?= $header ?></div><?php endif; ?>
	<?php if (!empty($toolbar)): ?><div class="table-toolbar"><?= $toolbar ?></div><?php endif; ?>
	<div class="table-responsive">
		<table class="table table-hover align-middle">
			<thead data-table-head></thead>
			<tbody data-table-body></tbody>
		</table>
	</div>
	<div class="table-footer" data-table-footer></div>
</div>

<?php $layout = 'app'; $scripts = ['admin.js']; ?>
<div data-page="admin-reports">
	<div class="row g-3">
		<?php foreach ([
			['submissions', 'Assignment submission report', 'Every student for every published assignment: status, timestamps, timeliness and marks.'],
			['missing', 'Missing submissions', 'Students who have not submitted after the deadline.'],
			['classes', 'Class-wise completion', 'Expected vs submitted work per class and subject, for assignments already due.'],
			['grades', 'Grading summary', 'Graded count, average, lowest and highest marks per assignment.'],
		] as [$type, $title, $desc]): ?>
			<div class="col-md-6">
				<div class="card h-100"><div class="card-body d-flex flex-column">
					<h2 class="h6"><?= e($title) ?></h2>
					<p class="small text-muted flex-grow-1"><?= e($desc) ?></p>
					<div class="d-flex gap-2 align-items-end">
						<div class="flex-grow-1">
							<label class="form-label small mb-1" for="rc-<?= e($type) ?>">Class</label>
							<select class="form-select form-select-sm" id="rc-<?= e($type) ?>" data-report-class><option value="">All classes</option></select>
						</div>
						<a class="btn btn-sm btn-primary" href="/api/reports/<?= e($type) ?>.csv" data-report="<?= e($type) ?>"><i class="bi bi-download me-1"></i>CSV</a>
					</div>
				</div></div>
			</div>
		<?php endforeach; ?>
	</div>
</div>

<?php $layout = 'app'; $scripts = ['student.js']; ?>
<div data-page="student-assignment" data-id="<?= (int) $id ?>">
	<p><a href="/student/assignments" data-back><i class="bi bi-arrow-left" aria-hidden="true"></i> My assignments</a></p>
	<div id="assignment-detail"><div class="loading-state">Loading…</div></div>
</div>

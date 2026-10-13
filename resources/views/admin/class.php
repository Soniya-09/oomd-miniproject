<?php $layout = 'app'; $scripts = ['admin.js']; ?>
<div data-page="admin-class" data-class-id="<?= (int) $id ?>">
	<p><a href="/admin/classes"><i class="bi bi-arrow-left"></i> All classes</a></p>
	<h2 class="h5 mb-3" id="class-heading">Loading…</h2>
	<div class="row g-3">
		<div class="col-lg-7">
			<div class="card">
				<div class="card-header">
					Enrolled students
					<div class="card-actions"><button class="btn btn-primary btn-sm" type="button" data-action="enroll"><i class="bi bi-person-plus me-1"></i>Enroll students</button></div>
				</div>
				<div id="class-students"></div>
			</div>
		</div>
		<div class="col-lg-5">
			<div class="card">
				<div class="card-header">
					Teachers &amp; subjects
					<div class="card-actions"><button class="btn btn-primary btn-sm" type="button" data-action="assign"><i class="bi bi-plus-lg me-1"></i>Assign teacher</button></div>
				</div>
				<div id="class-teaching"></div>
			</div>
		</div>
		<div class="col-lg-7">
			<div class="card">
				<div class="card-header">
					Weekly timetable
					<div class="card-actions"><button class="btn btn-primary btn-sm" type="button" data-action="add-period"><i class="bi bi-plus-lg me-1"></i>Add period</button></div>
				</div>
				<div id="class-timetable"></div>
			</div>
		</div>
		<div class="col-lg-5">
			<div class="card">
				<div class="card-header">
					Exams
					<div class="card-actions"><button class="btn btn-primary btn-sm" type="button" data-action="add-exam"><i class="bi bi-plus-lg me-1"></i>Schedule exam</button></div>
				</div>
				<div id="class-exams"></div>
			</div>
		</div>
	</div>
</div>

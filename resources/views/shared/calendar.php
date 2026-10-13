<?php $layout = 'app'; $scripts = ['shared.js']; ?>
<div data-page="calendar">
	<div class="card">
		<div class="card-header">
			<div class="btn-group btn-group-sm" role="group" aria-label="Navigate">
				<button class="btn btn-light" type="button" data-nav="-1" aria-label="Previous"><i class="bi bi-chevron-left"></i></button>
				<button class="btn btn-light" type="button" data-nav="0">Today</button>
				<button class="btn btn-light" type="button" data-nav="1" aria-label="Next"><i class="bi bi-chevron-right"></i></button>
			</div>
			<h2 class="h6 mb-0 ms-2" id="cal-title" aria-live="polite"></h2>
			<div class="card-actions">
				<div class="btn-group btn-group-sm" role="group" aria-label="View">
					<input type="radio" class="btn-check" name="cv" id="cv-month" value="month" checked><label class="btn btn-outline-primary" for="cv-month">Month</label>
					<input type="radio" class="btn-check" name="cv" id="cv-week" value="week"><label class="btn btn-outline-primary" for="cv-week">Week</label>
					<input type="radio" class="btn-check" name="cv" id="cv-agenda" value="agenda"><label class="btn btn-outline-primary" for="cv-agenda">Agenda</label>
				</div>
			</div>
		</div>
		<div class="px-3 py-2 small d-flex flex-wrap gap-2 border-bottom" aria-label="Legend">
			<span class="cal-event ev-assignment w-auto d-inline-block">Assignment deadline</span>
			<span class="cal-event ev-task w-auto d-inline-block">Personal task</span>
			<span class="cal-event ev-exam w-auto d-inline-block">Exam</span>
			<span class="cal-event ev-class w-auto d-inline-block">Class</span>
			<span class="cal-event ev-announcement w-auto d-inline-block">Announcement</span>
		</div>
		<div id="calendar-body"></div>
	</div>
</div>

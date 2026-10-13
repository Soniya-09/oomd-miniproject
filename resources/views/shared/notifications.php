<?php $layout = 'app'; $scripts = ['shared.js']; ?>
<div data-page="notifications">
	<div class="card">
		<div class="card-header">
			<div class="btn-group btn-group-sm" role="group" aria-label="Show">
				<input type="radio" class="btn-check" name="nf" id="nf-all" value="" checked><label class="btn btn-outline-primary" for="nf-all">All</label>
				<input type="radio" class="btn-check" name="nf" id="nf-unread" value="1"><label class="btn btn-outline-primary" for="nf-unread">Unread</label>
			</div>
			<div class="card-actions"><button class="btn btn-sm btn-light" type="button" data-action="read-all"><i class="bi bi-check2-all me-1"></i>Mark all as read</button></div>
		</div>
		<div id="notification-list" aria-live="polite"></div>
		<div class="table-footer" id="notification-footer"></div>
	</div>
</div>

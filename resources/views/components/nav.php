<?php
/** Role-specific sidebar. Only links the role can use are rendered. */
$items = match ($user['role']) {
	'admin' => [
		['/admin', 'speedometer2', 'Overview'],
		['/admin/users', 'people', 'Users'],
		['/admin/students', 'mortarboard', 'Students'],
		['/admin/teachers', 'person-workspace', 'Teachers'],
		['/admin/classes', 'collection', 'Classes'],
		['/admin/subjects', 'book', 'Subjects'],
		['/admin/sessions', 'calendar-range', 'Academic Sessions'],
		['/admin/announcements', 'megaphone', 'Announcements'],
		['/admin/reports', 'file-earmark-bar-graph', 'Reports'],
		['/admin/settings', 'gear', 'Settings'],
		['/admin/audit-logs', 'shield-check', 'Audit Logs'],
	],
	'teacher' => [
		['/teacher', 'speedometer2', 'Dashboard'],
		['/teacher/classes', 'collection', 'My Classes'],
		['/teacher/assignments', 'journal-text', 'Assignments'],
		['/teacher/submissions', 'inbox', 'Submissions'],
		['/teacher/announcements', 'megaphone', 'Announcements'],
		['/teacher/analytics', 'bar-chart', 'Analytics'],
	],
	default => [
		['/student', 'speedometer2', 'Dashboard'],
		['/student/assignments', 'journal-text', 'My Assignments'],
		['/student/tasks', 'check2-square', 'Personal Tasks'],
		['/student/calendar', 'calendar3', 'Calendar'],
		['/notifications', 'bell', 'Notifications'],
		['/student/progress', 'graph-up', 'My Progress'],
	],
};
$items[] = ['/profile', 'person-circle', 'Profile'];
$current = $path ?? '';
?>
<nav class="sidebar-nav" aria-label="Main">
	<ul class="nav flex-column">
		<?php foreach ($items as [$href, $icon, $label]): ?>
			<?php $active = $current === $href || ($href !== '/admin' && $href !== '/teacher' && $href !== '/student' && str_starts_with($current, $href . '/')); ?>
			<li class="nav-item">
				<a class="nav-link<?= $active ? ' active' : '' ?>" href="<?= e($href) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
					<i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i><span><?= e($label) ?></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>

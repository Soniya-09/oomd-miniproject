<?php $layout = 'auth'; ?>
<h1 class="h4 mb-2"><?= e($status) ?> · <?= e(match ((int) $status) { 403 => 'Access denied', 404 => 'Page not found', 429 => 'Slow down', default => 'Something went wrong' }) ?></h1>
<p class="text-muted"><?= e($message) ?></p>
<a class="btn btn-primary" href="/">Go to my dashboard</a>

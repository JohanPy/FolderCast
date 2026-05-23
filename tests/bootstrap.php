<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// If the app is executed inside a full Nextcloud checkout, also load the core
// test bootstrap. This keeps standalone CI runs working in this repository.
$nextcloudBootstrap = __DIR__ . '/../../../tests/bootstrap.php';
if (is_file($nextcloudBootstrap)) {
	require_once $nextcloudBootstrap;
}

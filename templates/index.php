<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\FolderCast\AppInfo\Application::APP_ID, OCA\FolderCast\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\FolderCast\AppInfo\Application::APP_ID, OCA\FolderCast\AppInfo\Application::APP_ID . '-main');

?>

<div id="foldercast"></div>

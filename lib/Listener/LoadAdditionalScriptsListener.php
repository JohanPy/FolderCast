<?php

declare(strict_types=1);

namespace OCA\FolderCast\Listener;

use OCA\FolderCast\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * @implements IEventListener<\OCA\Files\Event\LoadAdditionalScriptsEvent>
 */
class LoadAdditionalScriptsListener implements IEventListener
{
    public function handle(Event $event): void
    {
        Util::addInitScript(Application::APP_ID, 'foldercast-files');
    }
}

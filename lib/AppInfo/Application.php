<?php

declare(strict_types=1);

namespace OCA\FolderCast\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'foldercast';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		$context->registerService(\OCA\FolderCast\Service\MetadataService::class, function ($c) {
			return new \OCA\FolderCast\Service\MetadataService(
				$c->query(\Psr\Log\LoggerInterface::class)
			);
		});
		$context->registerService(\OCA\FolderCast\Service\FeedService::class, function ($c) {
			return new \OCA\FolderCast\Service\FeedService(
				$c->query(\OCA\FolderCast\Db\FeedMapper::class),
				$c->query(\OCA\FolderCast\Service\MetadataService::class),
				$c->query(\OCP\Files\IRootFolder::class),
				$c->query(\OCP\IURLGenerator::class),
				$c->query(\OCP\ICacheFactory::class),
				$c->query(\Psr\Log\LoggerInterface::class)
			);
		});
		$context->registerService(\OCA\FolderCast\Controller\ApiController::class, function ($c) {
			return new \OCA\FolderCast\Controller\ApiController(
				$c->query('AppName'),
				$c->query(\OCP\IRequest::class),
				$c->query(\OCA\FolderCast\Db\FeedMapper::class),
				$c->query(\OCP\IUserSession::class),
				$c->query(\OCP\Security\ISecureRandom::class),
				$c->query(\OCP\Files\IRootFolder::class),
				$c->query(\OCA\FolderCast\Service\FeedService::class)
			);
		});
	}

	public function boot(IBootContext $context): void
	{
		\OCP\Util::addScript('foldercast', 'foldercast-files');
	}
}

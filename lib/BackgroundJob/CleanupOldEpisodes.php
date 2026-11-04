<?php

declare(strict_types=1);

namespace OCA\FolderCast\BackgroundJob;

use OCA\FolderCast\Db\FeedMapper;
use OCA\FolderCast\Service\FeedService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class CleanupOldEpisodes extends TimedJob
{
    private FeedMapper $mapper;
    private FeedService $service;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        FeedMapper $mapper,
        FeedService $service,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->setInterval(6 * 3600); // 6 hours
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
        $this->mapper = $mapper;
        $this->service = $service;
        $this->logger = $logger;
    }

    protected function run($argument): void
    {
        $feeds = $this->mapper->findAll();
        foreach ($feeds as $feed) {
            try {
                $config = json_decode($feed->getConfiguration() ?? '{}', true);
                if (!is_array($config) || (int)($config['autoremoveDays'] ?? 0) <= 0) {
                    continue;
                }

                $deleted = $this->service->cleanupOldEpisodes($feed);
                if ($deleted > 0) {
                    $this->logger->info("FolderCast: cleaned up {$deleted} old episode(s) for feed {$feed->getId()}");
                }
            } catch (\Throwable $e) {
                $this->logger->error("FolderCast: failed to cleanup feed {$feed->getId()}: " . $e->getMessage(), [
                    'feedId' => $feed->getId(),
                    'exception' => $e,
                ]);
            }
        }
    }
}

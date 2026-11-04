<?php

declare(strict_types=1);

namespace OCA\FolderCast\Tests\Unit\BackgroundJob;

use OCA\FolderCast\BackgroundJob\CleanupOldEpisodes;
use OCA\FolderCast\Db\Feed;
use OCA\FolderCast\Db\FeedMapper;
use OCA\FolderCast\Service\FeedService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanupOldEpisodesTest extends TestCase
{
    public function testRunCleansUpEligibleFeeds(): void
    {
        $timeFactory = $this->createMock(ITimeFactory::class);
        $mapper = $this->createMock(FeedMapper::class);
        $service = $this->createMock(FeedService::class);
        $logger = $this->createMock(LoggerInterface::class);

        $feedDisabled = new Feed();
        $feedDisabled->setId(1);
        $feedDisabled->setConfiguration(json_encode(['autoremoveDays' => 0]));

        $feedActive = new Feed();
        $feedActive->setId(2);
        $feedActive->setConfiguration(json_encode(['autoremoveDays' => 14]));

        $feedThrowing = new Feed();
        $feedThrowing->setId(3);
        $feedThrowing->setConfiguration(json_encode(['autoremoveDays' => 7]));

        $mapper->method('findAll')->willReturn([$feedDisabled, $feedActive, $feedThrowing]);

        $service->expects($this->exactly(2))
            ->method('cleanupOldEpisodes')
            ->willReturnCallback(function (Feed $feed) use ($feedActive, $feedThrowing) {
                if ($feed->getId() === $feedActive->getId()) {
                    return 3;
                }
                if ($feed->getId() === $feedThrowing->getId()) {
                    throw new \RuntimeException('Storage unavailable');
                }
                return 0;
            });

        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('cleaned up 3 old episode(s) for feed 2'));

        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('failed to cleanup feed 3'));

        $job = new CleanupOldEpisodes($timeFactory, $mapper, $service, $logger);

        // TimedJob::run is protected, invoke via Reflection
        $reflection = new \ReflectionClass($job);
        $runMethod = $reflection->getMethod('run');
        $runMethod->setAccessible(true);
        $runMethod->invoke($job, null);
    }
}

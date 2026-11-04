<?php

declare(strict_types=1);

namespace OCA\FolderCast\Tests\Unit\Service;

use OCA\FolderCast\Db\Feed;
use OCA\FolderCast\Db\FeedMapper;
use OCA\FolderCast\Service\FeedService;
use OCA\FolderCast\Service\MetadataService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FeedServiceTest extends TestCase
{
    private FeedMapper $mapper;
    private MetadataService $metadataService;
    private IRootFolder $rootFolder;
    private IURLGenerator $urlGenerator;
    private ICacheFactory $cacheFactory;
    private LoggerInterface $logger;
    private FeedService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = $this->createMock(FeedMapper::class);
        $this->metadataService = $this->createMock(MetadataService::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $cache = $this->createMock(ICache::class);
        $this->cacheFactory->method('createDistributed')->willReturn($cache);

        $this->urlGenerator->method('linkToRouteAbsolute')
            ->willReturnCallback(function (string $route, array $params = []) {
                return 'https://cloud.example.com/' . $route . '?' . http_build_query($params);
            });

        $this->service = new FeedService(
            $this->mapper,
            $this->metadataService,
            $this->rootFolder,
            $this->urlGenerator,
            $this->cacheFactory,
            $this->logger
        );
    }

    private function createMockFile(
        int $id,
        string $name,
        string $path,
        string $mime,
        int $size = 1024,
        int $mtime = 1700000000
    ): File {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn($name);
        $file->method('getPath')->willReturn($path);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getSize')->willReturn($size);
        $file->method('getMtime')->willReturn($mtime);
        return $file;
    }

    public function testGetFileRejectsPathOutsideFeedFolder(): void
    {
        $feed = new Feed();
        $feed->setToken('valid-token');
        $feed->setUserId('testuser');
        $feed->setFolderId(10);
        $feed->setConfiguration(json_encode(['flatten' => true]));

        $this->mapper->method('findByToken')->with('valid-token')->willReturn($feed);

        $feedFolder = $this->createMock(Folder::class);
        $feedFolder->method('getId')->willReturn(10);
        $feedFolder->method('getPath')->willReturn('/testuser/files/Podcast');

        // Secret file in another folder
        $secretFile = $this->createMockFile(99, 'secret.mp3', '/testuser/files/PodcastSecret/secret.mp3', 'audio/mpeg');

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturnCallback(function (int $id) use ($feedFolder, $secretFile) {
            if ($id === 10) return [$feedFolder];
            if ($id === 99) return [$secretFile];
            return [];
        });

        $this->rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

        $result = $this->service->getFile('valid-token', 99);
        $this->assertNull($result, 'Files outside the feed folder must not be accessible');
    }

    public function testGetFileRejectsNonAudioOrPdfFiles(): void
    {
        $feed = new Feed();
        $feed->setToken('valid-token');
        $feed->setUserId('testuser');
        $feed->setFolderId(10);
        $feed->setConfiguration(json_encode(['flatten' => true]));

        $this->mapper->method('findByToken')->with('valid-token')->willReturn($feed);

        $feedFolder = $this->createMock(Folder::class);
        $feedFolder->method('getId')->willReturn(10);
        $feedFolder->method('getPath')->willReturn('/testuser/files/Podcast');

        // PDF file inside the podcast folder
        $pdfFile = $this->createMockFile(101, 'notes.pdf', '/testuser/files/Podcast/notes.pdf', 'application/pdf');

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturnCallback(function (int $id) use ($feedFolder, $pdfFile) {
            if ($id === 10) return [$feedFolder];
            if ($id === 101) return [$pdfFile];
            return [];
        });

        $this->rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

        $result = $this->service->getFile('valid-token', 101);
        $this->assertNull($result, 'Non-audio files like PDFs must be rejected');
    }

    public function testGetFileRejectsSubfolderWhenFlattenIsFalse(): void
    {
        $feed = new Feed();
        $feed->setToken('valid-token');
        $feed->setUserId('testuser');
        $feed->setFolderId(10);
        $feed->setConfiguration(json_encode(['flatten' => false]));

        $this->mapper->method('findByToken')->with('valid-token')->willReturn($feed);

        $feedFolder = $this->createMock(Folder::class);
        $feedFolder->method('getId')->willReturn(10);
        $feedFolder->method('getPath')->willReturn('/testuser/files/Podcast');

        // File inside subfolder
        $subFile = $this->createMockFile(102, 'sub.mp3', '/testuser/files/Podcast/Season1/sub.mp3', 'audio/mpeg');

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturnCallback(function (int $id) use ($feedFolder, $subFile) {
            if ($id === 10) return [$feedFolder];
            if ($id === 102) return [$subFile];
            return [];
        });

        $this->rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

        $result = $this->service->getFile('valid-token', 102);
        $this->assertNull($result, 'Subfolder files must be rejected when flatten is false');
    }

    public function testGenerateXmlProducesValidFeedWithCorrectMimeAndGuid(): void
    {
        $feed = new Feed();
        $feed->setId(1);
        $feed->setToken('valid-token');
        $feed->setUserId('testuser');
        $feed->setFolderId(10);
        $feed->setConfiguration(json_encode([
            'title' => "L'Émission Test & Spéciale",
            'description' => 'Description avec <balises> et &amp;',
            'flatten' => true,
            'sortBy' => 'date_desc',
        ]));

        $folder = $this->createMock(Folder::class);
        $folder->method('getName')->willReturn('Podcast');
        $folder->method('getPath')->willReturn('/testuser/files/Podcast');
        $folder->method('nodeExists')->willReturn(false);

        // Episode with octet-stream mime but .mp3 extension
        $file1 = $this->createMockFile(201, 'ep1.mp3', '/testuser/files/Podcast/ep1.mp3', 'application/octet-stream', 5000, 1700000000);
        // Episode 2
        $file2 = $this->createMockFile(202, 'ep2.m4a', '/testuser/files/Podcast/ep2.m4a', 'audio/mp4', 8000, 1700001000);

        $folder->method('getDirectoryListing')->willReturn([$file1, $file2]);

        $this->metadataService->method('getMetadata')->willReturnCallback(function (File $f) {
            if ($f->getId() === 201) {
                return [
                    'title' => "Episode 1 L'Histoire",
                    'artist' => 'Animateur',
                    'date' => '2025-01-01',
                    'duration' => 120,
                    'url' => 'https://example.com/ep1',
                    'description' => 'Desc 1',
                    'has_cover' => false,
                ];
            }
            return [
                'title' => 'Episode 2',
                'artist' => 'Animateur',
                'date' => '2025-01-02',
                'duration' => 240,
                'url' => 'invalid-not-an-url',
                'description' => 'Desc 2',
                'has_cover' => true,
            ];
        });

        $xml = $this->service->generateXml($feed, $folder);

        // Verify valid XML structure
        $parsed = simplexml_load_string($xml);
        $this->assertInstanceOf(\SimpleXMLElement::class, $parsed);
        $this->assertSame("L'Émission Test & Spéciale", (string)$parsed->channel->title);

        $items = $parsed->channel->item;
        $this->assertCount(2, $items);

        // Sorted date_desc: ep2 (2025-01-02) should be first
        $this->assertSame('Episode 2', (string)$items[0]->title);
        $this->assertSame('foldercast-1-202', (string)$items[0]->guid);
        $this->assertSame('audio/mp4', (string)$items[0]->enclosure['type']);

        $this->assertSame("Episode 1 L'Histoire", (string)$items[1]->title);
        $this->assertSame('foldercast-1-201', (string)$items[1]->guid);
        // Octet-stream on .mp3 should be mapped to audio/mpeg
        $this->assertSame('audio/mpeg', (string)$items[1]->enclosure['type']);
    }

    public function testGenerateXmlNeverDeletesFiles(): void
    {
        $feed = new Feed();
        $feed->setId(1);
        $feed->setToken('valid-token');
        $feed->setUserId('testuser');
        $feed->setFolderId(10);
        $feed->setConfiguration(json_encode([
            'autoremoveDays' => 1, // Autoremove is configured
        ]));

        $folder = $this->createMock(Folder::class);
        $folder->method('getName')->willReturn('Podcast');
        $folder->method('getPath')->willReturn('/testuser/files/Podcast');
        $folder->method('nodeExists')->willReturn(false);

        // Very old file (1 year old)
        $oldFile = $this->createMockFile(301, 'old.mp3', '/testuser/files/Podcast/old.mp3', 'audio/mpeg', 1000, time() - 365 * 86400);

        // Must NEVER call delete() during generateXml()
        $oldFile->expects($this->never())->method('delete');

        $folder->method('getDirectoryListing')->willReturn([$oldFile]);
        $this->metadataService->method('getMetadata')->willReturn([
            'title' => 'Old Episode',
            'artist' => null,
            'date' => null,
            'duration' => 0,
            'has_cover' => false,
        ]);

        $this->service->generateXml($feed, $folder);
    }

    public function testIsEpisodeIncludesAudioNamedCover(): void
    {
        $feedFolder = $this->createMock(Folder::class);
        $feedFolder->method('getPath')->willReturn('/user/files/Podcast');

        // File named cover.mp3 with audio mime
        $audioCover = $this->createMockFile(501, 'cover.mp3', '/user/files/Podcast/cover.mp3', 'audio/mpeg');
        $this->assertTrue($this->service->isEpisode($audioCover, $feedFolder, true));
    }

    public function testIsEpisodeRejectsCoverImagesAndLogo(): void
    {
        $feedFolder = $this->createMock(Folder::class);
        $feedFolder->method('getPath')->willReturn('/user/files/Podcast');

        $coverJpg = $this->createMockFile(502, 'cover.jpg', '/user/files/Podcast/cover.jpg', 'image/jpeg');
        $this->assertFalse($this->service->isEpisode($coverJpg, $feedFolder, true));

        $logoFile = $this->createMockFile(503, '_logo.png', '/user/files/Podcast/_logo.png', 'image/png');
        $this->assertFalse($this->service->isEpisode($logoFile, $feedFolder, true));

        $jsonFile = $this->createMockFile(504, 'podcast.json', '/user/files/Podcast/podcast.json', 'application/json');
        $this->assertFalse($this->service->isEpisode($jsonFile, $feedFolder, true));
    }

    public function testCleanupOldEpisodesDeletesOnlyOldEpisodes(): void
    {
        $feed = new Feed();
        $feed->setId(10);
        $feed->setToken('tok');
        $feed->setUserId('testuser');
        $feed->setFolderId(1);
        $feed->setConfiguration(json_encode([
            'autoremoveDays' => 30,
            'flatten' => true,
        ]));

        $folder = $this->createMock(Folder::class);
        $folder->method('getPath')->willReturn('/testuser/files/Podcast');

        $now = time();
        // File 1: 40 days old -> should be deleted
        $oldFile = $this->createMockFile(101, 'old.mp3', '/testuser/files/Podcast/old.mp3', 'audio/mpeg', 1000, $now - (40 * 86400));
        $oldFile->expects($this->once())->method('delete');

        // File 2: 10 days old -> should be kept
        $freshFile = $this->createMockFile(102, 'fresh.mp3', '/testuser/files/Podcast/fresh.mp3', 'audio/mpeg', 1000, $now - (10 * 86400));
        $freshFile->expects($this->never())->method('delete');

        $folder->method('getDirectoryListing')->willReturn([$oldFile, $freshFile]);

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->with(1)->willReturn([$folder]);
        $this->rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

        $deletedCount = $this->service->cleanupOldEpisodes($feed);
        $this->assertSame(1, $deletedCount);
    }
}

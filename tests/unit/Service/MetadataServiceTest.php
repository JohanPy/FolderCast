<?php

declare(strict_types=1);

namespace OCA\FolderCast\Tests\Unit\Service;

use OCA\FolderCast\Service\MetadataService;
use OCP\Files\File;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MetadataServiceTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = realpath(__DIR__ . '/../../fixtures/episode.mp3') ?: '';
        $this->assertFileExists($this->fixturePath);
    }

    private function mockFile(string $path, int $id = 42): File
    {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn(basename($path));
        $file->method('getPath')->willReturn($path);
        $file->method('getInternalPath')->willReturn($path);
        $file->method('getSize')->willReturn(filesize($path) ?: 0);
        $file->method('getMtime')->willReturn(filemtime($path) ?: time());
        $file->method('getMimeType')->willReturn('audio/mpeg');
        $file->method('fopen')->willReturnCallback(fn() => fopen($path, 'rb'));

        $storage = $this->createMock(\OCP\Files\Storage\IStorage::class);
        $storage->method('getLocalFile')->willReturn($path);
        $file->method('getStorage')->willReturn($storage);

        return $file;
    }

    public function testGetMetadataParsesTagsCorrectlyWithoutDoubleEscaping(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $service = new MetadataService($logger);

        $file = $this->mockFile($this->fixturePath);
        $meta = $service->getMetadata($file);

        $this->assertSame("L'État & l'université <test>", $meta['title']);
        $this->assertSame('Artiste Démo', $meta['artist']);
        $this->assertSame('Album Test', $meta['album']);
        $this->assertGreaterThan(0, $meta['duration']);
        $this->assertTrue($meta['has_cover']);
    }

    public function testGetCoverReturnsValidImageData(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $service = new MetadataService($logger);

        $file = $this->mockFile($this->fixturePath);
        $cover = $service->getCover($file);

        $this->assertNotNull($cover);
        $this->assertSame('image/png', $cover['mime']);
        $this->assertNotEmpty($cover['data']);
    }

    public function testMetadataCacheAvoidsSecondAnalysis(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $cache = $this->createMock(ICache::class);
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        // First call: cache miss, then set is called
        $cachedData = [
            'title' => 'Cached Title',
            'artist' => 'Cached Artist',
            'album' => 'Cached Album',
            'duration' => 10,
            'filesize' => 100,
            'mime_type' => 'audio/mpeg',
            'cover' => null,
            'has_cover' => false,
        ];

        $cache->expects($this->once())
            ->method('get')
            ->willReturn($cachedData);

        $service = new MetadataService($logger, $cacheFactory);
        $file = $this->mockFile($this->fixturePath);

        // This call should return from cache directly
        $meta = $service->getMetadata($file);
        $this->assertSame('Cached Title', $meta['title']);
    }
}

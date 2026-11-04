<?php

declare(strict_types=1);

namespace OCA\FolderCast\Tests\Unit\Controller;

use OCA\FolderCast\Controller\FeedController;
use OCA\FolderCast\Http\RangeStreamResponse;
use OCA\FolderCast\Service\FeedService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FeedControllerTest extends TestCase
{
    private string $appName = 'foldercast';
    private IRequest $request;
    private FeedService $service;
    private LoggerInterface $logger;
    private FeedController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = $this->createMock(IRequest::class);
        $this->service = $this->createMock(FeedService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new FeedController(
            $this->appName,
            $this->request,
            $this->service,
            $this->logger
        );
    }

    public function testShowReturnsRssXml(): void
    {
        $xml = '<?xml version="1.0"?><rss><channel><title>Test</title></channel></rss>';
        $this->service->method('getFeed')->with('valid-token')->willReturn($xml);
        $this->request->method('getHeader')->with('If-None-Match')->willReturn('');

        $response = $this->controller->show('valid-token');

        $this->assertInstanceOf(StreamResponse::class, $response);
        $headers = $response->getHeaders();
        $this->assertStringContainsString('application/rss+xml', $headers['Content-Type']);
        $this->assertStringContainsString('feed.xml', $headers['Content-Disposition']);
        $this->assertSame('"' . md5($xml) . '"', $headers['ETag']);
    }

    public function testShowReturns304WhenEtagMatches(): void
    {
        $xml = '<rss></rss>';
        $etag = md5($xml);
        $this->service->method('getFeed')->with('valid-token')->willReturn($xml);
        $this->request->method('getHeader')->with('If-None-Match')->willReturn('"' . $etag . '"');

        $response = $this->controller->show('valid-token');

        $this->assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
        $this->assertSame('"' . $etag . '"', $response->getHeaders()['ETag']);
    }

    public function testShowReturns404OnDoesNotExist(): void
    {
        $this->service->method('getFeed')->with('invalid-token')->willThrowException(new DoesNotExistException('Feed not found'));

        $response = $this->controller->show('invalid-token');

        $this->assertInstanceOf(DataResponse::class, $response);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    public function testDownloadFullContentWhenNoRange(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getSize')->willReturn(5000);
        $file->method('getMimeType')->willReturn('audio/mpeg');
        $file->method('getName')->willReturn('episode 01.mp3');
        $file->method('getEtag')->willReturn('etag123');
        $file->method('getMTime')->willReturn(1700000000);
        $file->method('fopen')->with('rb')->willReturn(fopen('php://memory', 'r+'));

        $this->service->method('getFile')->with('valid-token', 42)->willReturn($file);
        $this->request->method('getHeader')->willReturnCallback(function (string $header) {
            return '';
        });

        $response = $this->controller->download('valid-token', 42);

        $this->assertInstanceOf(RangeStreamResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $headers = $response->getHeaders();
        $this->assertSame('audio/mpeg', $headers['Content-Type']);
        $this->assertSame('5000', $headers['Content-Length']);
        $this->assertSame('"etag123"', $headers['ETag']);
    }

    public function testDownloadReturns304WhenIfNoneMatchMatches(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getEtag')->willReturn('etag123');
        $file->method('getSize')->willReturn(5000);
        $file->method('getName')->willReturn('episode.mp3');
        $file->method('getMTime')->willReturn(1700000000);

        $this->service->method('getFile')->with('valid-token', 42)->willReturn($file);
        $this->request->method('getHeader')->willReturnCallback(function (string $header) {
            if ($header === 'If-None-Match') return '"etag123"';
            return '';
        });

        $response = $this->controller->download('valid-token', 42);

        $this->assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
        $this->assertSame('"etag123"', $response->getHeaders()['ETag']);
    }

    public function testDownloadPartialContentWhenRangeProvided(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getSize')->willReturn(1000);
        $file->method('getMimeType')->willReturn('audio/mpeg');
        $file->method('getName')->willReturn('test.mp3');
        $file->method('getEtag')->willReturn('etag456');
        $file->method('getMTime')->willReturn(1700000000);
        $file->method('fopen')->with('rb')->willReturn(fopen('php://memory', 'r+'));

        $this->service->method('getFile')->with('valid-token', 42)->willReturn($file);
        $this->request->method('getHeader')->willReturnCallback(function (string $header) {
            if ($header === 'Range') return 'bytes=0-499';
            return '';
        });

        $response = $this->controller->download('valid-token', 42);

        $this->assertInstanceOf(RangeStreamResponse::class, $response);
        $this->assertSame(Http::STATUS_PARTIAL_CONTENT, $response->getStatus());
        $headers = $response->getHeaders();
        $this->assertSame('bytes 0-499/1000', $headers['Content-Range']);
        $this->assertSame('500', $headers['Content-Length']);
    }

    public function testDownloadReturns416OnInvalidRange(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getSize')->willReturn(1000);
        $file->method('getName')->willReturn('test.mp3');
        $file->method('getEtag')->willReturn('etag456');
        $file->method('getMTime')->willReturn(1700000000);

        $this->service->method('getFile')->with('valid-token', 42)->willReturn($file);
        $this->request->method('getHeader')->willReturnCallback(function (string $header) {
            if ($header === 'Range') return 'bytes=5000-6000';
            return '';
        });

        $response = $this->controller->download('valid-token', 42);

        $this->assertSame(Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE, $response->getStatus());
        $this->assertSame('bytes */1000', $response->getHeaders()['Content-Range']);
    }

    public function testLogoReturnsStream(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getEtag')->willReturn('logoetag');
        $file->method('getMimeType')->willReturn('image/png');
        $file->method('getSize')->willReturn(1234);
        $file->method('fopen')->with('rb')->willReturn(fopen('php://memory', 'r+'));

        $this->service->method('getLogoFile')->with('valid-token')->willReturn($file);
        $this->request->method('getHeader')->with('If-None-Match')->willReturn('');

        $response = $this->controller->logo('valid-token');

        $this->assertInstanceOf(StreamResponse::class, $response);
        $this->assertSame('image/png', $response->getHeaders()['Content-Type']);
        $this->assertSame('"logoetag"', $response->getHeaders()['ETag']);
    }

    public function testLogoReturns304WhenEtagMatches(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getEtag')->willReturn('logoetag');

        $this->service->method('getLogoFile')->with('valid-token')->willReturn($file);
        $this->request->method('getHeader')->with('If-None-Match')->willReturn('"logoetag"');

        $response = $this->controller->logo('valid-token');

        $this->assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
        $this->assertSame('"logoetag"', $response->getHeaders()['ETag']);
    }

    public function testCoverReturnsStream(): void
    {
        $coverData = 'binary-image-data';
        $this->service->method('getCover')->with('valid-token', 10)->willReturn([
            'data' => $coverData,
            'mime' => 'image/jpeg',
        ]);
        $this->request->method('getHeader')->with('If-None-Match')->willReturn('');

        $response = $this->controller->cover('valid-token', 10);

        $this->assertInstanceOf(StreamResponse::class, $response);
        $this->assertSame('image/jpeg', $response->getHeaders()['Content-Type']);
        $this->assertSame('"' . md5($coverData) . '"', $response->getHeaders()['ETag']);
    }

    public function testCoverReturns304WhenEtagMatches(): void
    {
        $coverData = 'binary-image-data';
        $etag = md5($coverData);
        $this->service->method('getCover')->with('valid-token', 10)->willReturn([
            'data' => $coverData,
            'mime' => 'image/jpeg',
        ]);
        $this->request->method('getHeader')->with('If-None-Match')->willReturn('"' . $etag . '"');

        $response = $this->controller->cover('valid-token', 10);

        $this->assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
        $this->assertSame('"' . $etag . '"', $response->getHeaders()['ETag']);
    }
}

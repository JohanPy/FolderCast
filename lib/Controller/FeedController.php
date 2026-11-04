<?php

declare(strict_types=1);

namespace OCA\FolderCast\Controller;

use OCA\FolderCast\Http\RangeStreamResponse;
use OCA\FolderCast\Service\FeedService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class FeedController extends Controller
{
    private const MIME_MAP = [
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'm4b' => 'audio/mp4',
        'aac' => 'audio/aac',
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'opus' => 'audio/opus',
        'flac' => 'audio/flac',
        'wav' => 'audio/wav',
        'mp4' => 'video/mp4',
        'm4v' => 'video/mp4',
    ];

    private FeedService $service;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        FeedService $service,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->logger = $logger;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function show(string $token): Response
    {
        try {
            $xml = $this->service->getFeed($token);

            $etag = '"' . md5($xml) . '"';
            $ifNoneMatch = $this->request->getHeader('If-None-Match');
            if (trim($ifNoneMatch) !== '' && str_contains($ifNoneMatch, md5($xml))) {
                $response = new Response();
                $response->setStatus(Http::STATUS_NOT_MODIFIED);
                $response->addHeader('ETag', $etag);
                return $response;
            }

            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $xml);
            rewind($stream);

            $response = new StreamResponse($stream);
            $response->addHeader('Content-Type', 'application/rss+xml; charset=utf-8');
            $response->addHeader('Content-Disposition', 'inline; filename="feed.xml"');
            $response->addHeader('X-Content-Type-Options', 'nosniff');
            $response->addHeader('ETag', $etag);

            return $response;
        } catch (DoesNotExistException $e) {
            return new DataResponse([], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('Error rendering feed {token}: ' . $e->getMessage(), [
                'token' => $token,
                'exception' => $e,
            ]);
            return new DataResponse([], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function download(string $token, int $fileId): Response
    {
        try {
            $file = $this->service->getFile($token, $fileId);
            if (!$file) {
                return new DataResponse([], Http::STATUS_NOT_FOUND);
            }

            $size = $file->getSize();
            $mime = $file->getMimeType();
            $fileName = $file->getName();
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if ($mime === 'application/octet-stream' || empty($mime)) {
                $mime = self::MIME_MAP[$ext] ?? 'audio/mpeg';
            }

            $asciiName = preg_replace('/["\r\n]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $fileName) ?: 'episode');
            $encodedName = rawurlencode($fileName);
            $disposition = sprintf('inline; filename="%s"; filename*=UTF-8\'\'%s', $asciiName, $encodedName);

            $fileEtag = $file->getEtag();
            $etag = !empty($fileEtag) ? '"' . $fileEtag . '"' : null;
            $mtime = $file->getMTime();
            $lastModified = gmdate('D, d M Y H:i:s \G\M\T', $mtime);

            $rangeHeader = $this->request->getHeader('Range');
            $ifNoneMatch = $this->request->getHeader('If-None-Match');

            if (trim($rangeHeader) === '' && $etag !== null && trim($ifNoneMatch) !== '' && str_contains($ifNoneMatch, $fileEtag)) {
                $response = new Response();
                $response->setStatus(Http::STATUS_NOT_MODIFIED);
                $response->addHeader('ETag', $etag);
                return $response;
            }

            $parsedRange = RangeStreamResponse::parseRange($rangeHeader, $size);

            if ($parsedRange === false) {
                $response = new Response();
                $response->setStatus(Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE);
                $response->addHeader('Content-Range', 'bytes */' . $size);
                $response->addHeader('Accept-Ranges', 'bytes');
                return $response;
            }

            $stream = $file->fopen('rb');
            if ($stream === false || !is_resource($stream)) {
                return new DataResponse([], Http::STATUS_NOT_FOUND);
            }

            if ($parsedRange === null) {
                // Full content 200
                $response = new RangeStreamResponse($stream, 0, $size, Http::STATUS_OK);
                $response->addHeader('Content-Type', $mime);
                $response->addHeader('Content-Length', (string) $size);
                $response->addHeader('Accept-Ranges', 'bytes');
                $response->addHeader('Content-Disposition', $disposition);
                $response->addHeader('Last-Modified', $lastModified);
                if ($etag !== null) {
                    $response->addHeader('ETag', $etag);
                }
                $response->addHeader('Pragma', 'public');
                return $response;
            }

            [$start, $end] = $parsedRange;
            $length = $end - $start + 1;

            $response = new RangeStreamResponse($stream, $start, $length, Http::STATUS_PARTIAL_CONTENT);
            $response->addHeader('Content-Type', $mime);
            $response->addHeader('Content-Range', sprintf('bytes %d-%d/%d', $start, $end, $size));
            $response->addHeader('Content-Length', (string) $length);
            $response->addHeader('Accept-Ranges', 'bytes');
            $response->addHeader('Content-Disposition', $disposition);
            $response->addHeader('Last-Modified', $lastModified);
            if ($etag !== null) {
                $response->addHeader('ETag', $etag);
            }
            $response->addHeader('Pragma', 'public');

            return $response;
        } catch (DoesNotExistException $e) {
            return new DataResponse([], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('Error downloading file {fileId}: ' . $e->getMessage(), [
                'fileId' => $fileId,
                'exception' => $e,
            ]);
            return new DataResponse([], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function logo(string $token): Response
    {
        try {
            $file = $this->service->getLogoFile($token);
            if (!$file) {
                return new DataResponse([], Http::STATUS_NOT_FOUND);
            }

            $fileEtag = $file->getEtag();
            $etag = !empty($fileEtag) ? '"' . $fileEtag . '"' : null;
            $ifNoneMatch = $this->request->getHeader('If-None-Match');
            if ($etag !== null && trim($ifNoneMatch) !== '' && str_contains($ifNoneMatch, $fileEtag)) {
                $response = new Response();
                $response->setStatus(Http::STATUS_NOT_MODIFIED);
                $response->addHeader('ETag', $etag);
                return $response;
            }

            $stream = $file->fopen('rb');
            if ($stream === false || !is_resource($stream)) {
                return new DataResponse([], Http::STATUS_NOT_FOUND);
            }

            $response = new StreamResponse($stream);
            $response->addHeader('Content-Type', $file->getMimeType());
            $response->addHeader('Content-Length', (string) $file->getSize());
            if ($etag !== null) {
                $response->addHeader('ETag', $etag);
            }
            $response->addHeader('Cache-Control', 'public, max-age=86400');
            $response->addHeader('Pragma', 'public');
            return $response;
        } catch (DoesNotExistException $e) {
            return new DataResponse([], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('Error serving logo for {token}: ' . $e->getMessage(), [
                'token' => $token,
                'exception' => $e,
            ]);
            return new DataResponse([], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function cover(string $token, int $fileId): Response
    {
        try {
            $cover = $this->service->getCover($token, $fileId);
            if (!$cover) {
                return new DataResponse([], Http::STATUS_NOT_FOUND);
            }

            $coverHash = md5($cover['data']);
            $etag = '"' . $coverHash . '"';
            $ifNoneMatch = $this->request->getHeader('If-None-Match');
            if (trim($ifNoneMatch) !== '' && str_contains($ifNoneMatch, $coverHash)) {
                $response = new Response();
                $response->setStatus(Http::STATUS_NOT_MODIFIED);
                $response->addHeader('ETag', $etag);
                return $response;
            }

            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $cover['data']);
            rewind($stream);

            $response = new StreamResponse($stream);
            $response->addHeader('Content-Type', $cover['mime']);
            $response->addHeader('Content-Length', (string) strlen($cover['data']));
            $response->addHeader('ETag', $etag);
            $response->addHeader('Cache-Control', 'public, max-age=86400');
            $response->addHeader('Pragma', 'public');
            return $response;
        } catch (DoesNotExistException $e) {
            return new DataResponse([], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('Error serving cover for {fileId}: ' . $e->getMessage(), [
                'fileId' => $fileId,
                'exception' => $e,
            ]);
            return new DataResponse([], Http::STATUS_NOT_FOUND);
        }
    }
}

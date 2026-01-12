<?php

declare(strict_types=1);

namespace OCA\FolderCast\Controller;

use OCA\FolderCast\Service\FeedService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

class FeedController extends Controller
{
    private FeedService $service;

    public function __construct(string $appName, IRequest $request, FeedService $service)
    {
        parent::__construct($appName, $request);
        $this->service = $service;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function show(string $token): Response
    {
        try {
            $xml = $this->service->getFeed($token);

            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $xml);
            rewind($stream);

            $response = new StreamResponse($stream);
            $response->addHeader('Content-Type', 'application/rss+xml; charset=utf-8');
            $response->addHeader('Content-Disposition', 'inline; filename="feed.xml"');
            $response->addHeader('X-Content-Type-Options', 'nosniff');

            return $response;
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function download(string $token, int $fileId): Response
    {
        try {
            $file = $this->service->getFile($token, $fileId);
            if (!$file) {
                return new DataResponse([], Http::STATUS_NOT_FOUND);
            }

            $response = new StreamResponse($file->fopen('rb'));
            $response->addHeader('Content-Type', $file->getMimeType());
            $response->addHeader('Content-Length', (string) $file->getSize());
            $response->addHeader('Content-Disposition', 'inline; filename="' . $file->getName() . '"');
            return $response;
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }
}

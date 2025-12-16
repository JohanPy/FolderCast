<?php

declare(strict_types=1);

namespace OCA\FolderCast\Controller;

use OCA\FolderCast\Service\FeedService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
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
            // Check if XML is empty or error?
            // getFeed throws exception or returns string.

            // We must return string for DataResponse.
            // However DataResponse usually encodes to JSON.
            // To return raw content, we should use a different response or just set data and content type?
            // DataResponse automatically json_encodes if it's an array/object.
            // If it's a string, it might still double quote it?
            // Better to use generic Response in this case or ensure raw output.
            // Nextcloud documentation: use DataDisplayResponse or just StreamResponse with string stream?
            // Actually, DataResponse with setStatus? 
            // Let's use generic Response and setBody.

            return new DataDisplayResponse(
                $xml,
                Http::STATUS_OK,
                ['Content-Type' => 'application/rss+xml; charset=utf-8']
            );
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
            // Use inline to allow streaming related behavior in browser, or attachment? Podcast players usually handle it.
            $response->addHeader('Content-Disposition', 'inline; filename="' . $file->getName() . '"');
            return $response;
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }
}

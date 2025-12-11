<?php

declare(strict_types=1);

namespace OCA\FolderCast\Service;

use OCA\FolderCast\Db\FeedMapper;
use OCA\FolderCast\Db\Feed;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class FeedService
{
    private FeedMapper $mapper;
    private MetadataService $metadataService;
    private IRootFolder $rootFolder;
    private IURLGenerator $urlGenerator;
    private ICache $cache;
    private LoggerInterface $logger;

    public function __construct(
        FeedMapper $mapper,
        MetadataService $metadataService,
        IRootFolder $rootFolder,
        IURLGenerator $urlGenerator,
        ICacheFactory $cacheFactory,
        LoggerInterface $logger
    ) {
        $this->mapper = $mapper;
        $this->metadataService = $metadataService;
        $this->rootFolder = $rootFolder;
        $this->urlGenerator = $urlGenerator;
        $this->cache = $cacheFactory->createDistributed('foldercast');
        $this->logger = $logger;
    }

    public function getFeed(string $token): string
    {
        $cacheKey = 'feed_xml_' . $token;
        if ($content = $this->cache->get($cacheKey)) {
            return $content;
        }

        $feed = $this->mapper->findByToken($token);
        $xml = $this->generateXml($feed);

        $this->cache->set($cacheKey, $xml, 3600); // 1 hour TTL default
        return $xml;
    }

    public function getFile(string $token, int $fileId): ?File
    {
        // Used by Controller to serve file
        $feed = $this->mapper->findByToken($token);
        // Security: verify fileId is inside feed's folder
        // For now, simplicity: just check if user has access.
        // BETTER: Check that fileId is a descendant of $feed->getFolderId()

        $userFolder = $this->rootFolder->getUserFolder($feed->getUserId());
        $nodes = $userFolder->getById($fileId);
        if (empty($nodes)) {
            return null;
        }
        /** @var File $file */
        $file = $nodes[0];

        // Verify hierarchy
        // This might be expensive. For prototype, we skip strict hierarchy check or rely on recursive path check?
        // A safe way: Check if file path starts with folder path.

        $feedFolderNodes = $userFolder->getById($feed->getFolderId());
        if (empty($feedFolderNodes)) {
            return null;
        }
        $feedFolder = $feedFolderNodes[0];

        if (strpos($file->getPath(), $feedFolder->getPath()) !== 0) {
            // File is not inside the feed folder
            return null;
        }

        return $file;
    }

    private function generateXml(Feed $feed): string
    {
        $userFolder = $this->rootFolder->getUserFolder($feed->getUserId());
        $folderNodes = $userFolder->getById($feed->getFolderId());

        if (empty($folderNodes)) {
            throw new \RuntimeException('Folder not found');
        }

        /** @var Folder $folder */
        $folder = $folderNodes[0];

        // Config Logic
        $config = json_decode($feed->getConfiguration() ?? '{}', true);
        $flatten = $config['flatten'] ?? true;

        // Metadata Logic (Title, Description, Image)
        $channelTitle = $folder->getName();
        $channelDesc = "Podcast from " . $folder->getName();
        $channelImage = null; // URL to image

        // Waterfall - Podcast.json
        if ($folder->nodeExists('podcast.json')) {
            try {
                $jsonFile = $folder->get('podcast.json');
                if ($jsonFile instanceof File) {
                    $meta = json_decode($jsonFile->getContent(), true);
                    $channelTitle = $meta['title'] ?? $channelTitle;
                    $channelDesc = $meta['description'] ?? $channelDesc;
                }
            } catch (\Throwable $e) {
            }
        }

        // Waterfall - Image
        // TODO: Expose image route? For now, skip.

        // Scanning
        $items = [];
        $this->scanFolder($folder, $items, $flatten);

        // XML Generation
        $output = '<?xml version="1.0" encoding="UTF-8"?>';
        $output .= '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">';
        $output .= '<channel>';
        $output .= '<title>' . htmlspecialchars($channelTitle) . '</title>';
        $output .= '<description>' . htmlspecialchars($channelDesc) . '</description>';

        foreach ($items as $item) {
            // $item is File
            $meta = $this->metadataService->getMetadata($item);
            $downloadUrl = $this->urlGenerator->linkToRouteAbsolute('foldercast.feed.download', [
                'token' => $feed->getToken(),
                'fileId' => $item->getId()
            ]);

            $output .= '<item>';
            $output .= '<title>' . htmlspecialchars($meta['title']) . '</title>';
            $output .= '<enclosure url="' . htmlspecialchars($downloadUrl) . '" length="' . $item->getSize() . '" type="' . $item->getMimeType() . '" />';
            $output .= '<guid>' . $item->getId() . '</guid>';
            if ($meta['duration']) {
                $output .= '<itunes:duration>' . $meta['duration'] . '</itunes:duration>';
            }
            $output .= '</item>';
        }

        $output .= '</channel>';
        $output .= '</rss>';

        return $output;
    }

    private function scanFolder(Folder $folder, array &$items, bool $recursive): void
    {
        $nodes = $folder->getDirectoryListing();
        foreach ($nodes as $node) {
            if ($node instanceof File) {
                $mime = $node->getMimeType();
                if (str_starts_with($mime, 'audio/')) {
                    $items[] = $node;
                }
            } elseif ($node instanceof Folder && $recursive) {
                $this->scanFolder($node, $items, true);
            }
        }
    }
}

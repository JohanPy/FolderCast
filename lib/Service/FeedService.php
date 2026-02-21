<?php

declare(strict_types=1);

namespace OCA\FolderCast\Service;

use OCA\FolderCast\Db\FeedMapper;
use OCA\FolderCast\Db\Feed;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\Files\File;
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

    public function getMetadataService(): MetadataService
    {
        return $this->metadataService;
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

    public function clearCache(string $token): void
    {
        $cacheKey = 'feed_xml_' . $token;
        $this->logger->info("FolderCast: Clearing cache for key: $cacheKey");
        $this->cache->remove($cacheKey);
        // Verify removal
        if ($this->cache->get($cacheKey)) {
            $this->logger->error("FolderCast: Cache key still exists after removal: $cacheKey");
        } else {
            $this->logger->info("FolderCast: Cache key removed successfully: $cacheKey");
        }
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

    public function getLogoFile(string $token): ?File
    {
        $feed = $this->mapper->findByToken($token);
        $config = json_decode($feed->getConfiguration() ?? '{}', true);

        if (empty($config['logoFileId'])) {
            return null;
        }

        $userFolder = $this->rootFolder->getUserFolder($feed->getUserId());
        $nodes = $userFolder->getById($config['logoFileId']);

        if (empty($nodes)) {
            return null;
        }

        return $nodes[0];
        return $nodes[0];
    }

    public function getCover(string $token, int $fileId): ?array
    {
        $file = $this->getFile($token, $fileId);
        if (!$file) {
            return null;
        }
        return $this->metadataService->getCover($file);
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
        $autoremoveDays = (int) ($config['autoremoveDays'] ?? 0);

        // Autoremove Cleanup Logic
        if ($autoremoveDays > 0) {
            $allNodes = $folder->getDirectoryListing(); // Shallow scan for cleanup
            foreach ($allNodes as $node) {
                if ($node instanceof File) {
                    $mtime = $node->getMtime();
                    // Check if file is older than N days
                    if (time() - $mtime > $autoremoveDays * 86400) {
                        // Avoid deleting the config file or logo
                        if ($node->getName() === 'podcast.json' || str_starts_with($node->getName(), '_logo.')) {
                            continue;
                        }
                        try {
                            $node->delete();
                        } catch (\Throwable $e) {
                            $this->logger->error('FolderCast Autoremove failed for ' . $node->getPath() . ': ' . $e->getMessage());
                        }
                    }
                }
            }
        }

        // Default Metadata (Folder Name)
        $channelTitle = $folder->getName();
        $channelDesc = "Podcast from " . $folder->getName();
        $channelAuthor = '';
        $channelImage = null;

        // Waterfall 1: podcast.json (File-based override)
        if ($folder->nodeExists('podcast.json')) {
            try {
                $jsonFile = $folder->get('podcast.json');
                if ($jsonFile instanceof File) {
                    $meta = json_decode($jsonFile->getContent(), true);
                    $channelTitle = $meta['title'] ?? $channelTitle;
                    $channelDesc = $meta['description'] ?? $channelDesc;
                    $channelAuthor = $meta['author'] ?? $channelAuthor;
                }
            } catch (\Throwable $e) {
            }
        }

        // Waterfall 2: Database Config (UI-based override) - Highest Priority
        if (!empty($config['title'])) {
            $channelTitle = $config['title'];
        }
        if (!empty($config['description'])) {
            $channelDesc = $config['description'];
        }
        if (!empty($config['author'])) {
            $channelAuthor = $config['author'];
        }

        // Logo logic: prioritized local file > remote url
        $logoUrl = null;
        if (!empty($config['logoFileId'])) {
            $logoUrl = $this->urlGenerator->linkToRouteAbsolute('foldercast.feed.logo', [
                'token' => $feed->getToken(),
                'v' => $config['logoFileId'] // Cache busting
            ]);
        } elseif (!empty($config['imageUrl'])) {
            $logoUrl = $config['imageUrl'];
        }

        // Scanning
        $items = [];
        $this->scanFolder($folder, $items, $flatten);

        // XML Generation
        $output = '<?xml version="1.0" encoding="UTF-8"?>';
        $output .= '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">';
        $output .= '<channel>';
        $output .= '<title>' . htmlspecialchars($channelTitle) . '</title>';
        $output .= '<description>' . htmlspecialchars($channelDesc) . '</description>';
        if ($channelAuthor) {
            $output .= '<itunes:author>' . htmlspecialchars($channelAuthor) . '</itunes:author>';
        }
        if ($logoUrl) {
            $output .= '<itunes:image href="' . htmlspecialchars($logoUrl) . '"/>';
            $output .= '<image><url>' . htmlspecialchars($logoUrl) . '</url><title>' . htmlspecialchars($channelTitle) . '</title></image>';
        }

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

            if (!empty($meta['date'])) {
                // Try to parse date, fallback to file mtime if invalid
                $ts = strtotime($meta['date']);
                if ($ts === false) {
                    $ts = $item->getMtime();
                }
                $output .= '<pubDate>' . date('r', $ts) . '</pubDate>';
            } else {
                $output .= '<pubDate>' . date('r', $item->getMtime()) . '</pubDate>';
            }

            if (!empty($meta['has_cover'])) {
                $coverUrl = $this->urlGenerator->linkToRouteAbsolute('foldercast.feed.cover', [
                    'token' => $feed->getToken(),
                    'fileId' => $item->getId()
                ]);
                $output .= '<itunes:image href="' . htmlspecialchars($coverUrl) . '"/>';
            }

            if (!empty($meta['description'])) {
                $output .= '<description>' . htmlspecialchars($meta['description']) . '</description>';
                $output .= '<itunes:summary>' . htmlspecialchars($meta['description']) . '</itunes:summary>';
            }

            if (!empty($meta['artist'])) {
                $output .= '<itunes:author>' . htmlspecialchars($meta['artist']) . '</itunes:author>';
            }

            if (!empty($meta['url'])) {
                $output .= '<link>' . htmlspecialchars($meta['url']) . '</link>';
            }

            if (!empty($meta['duration'])) {
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
                // Allow audio, video, and octet-stream (often MP3s with wrong mime)
                if (str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/') || $mime === 'application/octet-stream') {
                    $items[] = $node;
                }
            } elseif ($node instanceof Folder && $recursive) {
                $this->scanFolder($node, $items, true);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace OCA\FolderCast\Service;

use OCA\FolderCast\Db\Feed;
use OCA\FolderCast\Db\FeedMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class FeedService
{
    public const AUDIO_VIDEO_EXTENSIONS = [
        'mp3', 'm4a', 'm4b', 'aac', 'ogg', 'oga', 'opus', 'flac', 'wav', 'mp4', 'm4v'
    ];

    public const COVER_FILE_NAMES = [
        'cover.jpg', 'cover.jpeg', 'cover.png', 'cover.webp'
    ];

    public const MIME_MAP = [
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

    public function isEpisode(File $file, Folder $root, bool $recursive): bool
    {
        $fileName = $file->getName();
        $lowerName = strtolower($fileName);

        if (str_starts_with($fileName, '.')) {
            return false;
        }

        if (str_starts_with($fileName, '_logo.') || in_array($lowerName, self::COVER_FILE_NAMES, true) || $lowerName === 'podcast.json') {
            return false;
        }

        $folderPrefix = rtrim($root->getPath(), '/') . '/';
        $filePath = $file->getPath();
        if (!str_starts_with($filePath, $folderPrefix)) {
            return false;
        }

        if (!$recursive) {
            $relativePath = substr($filePath, strlen($folderPrefix));
            if (str_contains($relativePath, '/')) {
                return false;
            }
        }

        $mime = $file->getMimeType();
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($mime === 'application/octet-stream' || empty($mime)) {
            return in_array($ext, self::AUDIO_VIDEO_EXTENSIONS, true);
        }

        return str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/');
    }

    public function getFeed(string $token): string
    {
        $feed = $this->mapper->findByToken($token);
        $userFolder = $this->rootFolder->getUserFolder($feed->getUserId());
        $folderNodes = $userFolder->getById($feed->getFolderId());

        if (empty($folderNodes) || !($folderNodes[0] instanceof Folder)) {
            throw new \RuntimeException('Folder not found');
        }

        /** @var Folder $folder */
        $folder = $folderNodes[0];
        $etag = $folder->getEtag();
        $configHash = md5($feed->getConfiguration() ?? '{}');
        $cacheKey = 'feed_xml_v2_' . $token . '_' . $etag . '_' . $configHash;

        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $xml = $this->generateXml($feed, $folder);
        $this->cache->set($cacheKey, $xml, 3600);
        return $xml;
    }

    public function clearCache(string $token): void
    {
        try {
            $feed = $this->mapper->findByToken($token);
            $userFolder = $this->rootFolder->getUserFolder($feed->getUserId());
            $folderNodes = $userFolder->getById($feed->getFolderId());
            if (!empty($folderNodes) && $folderNodes[0] instanceof Folder) {
                $etag = $folderNodes[0]->getEtag();
                $configHash = md5($feed->getConfiguration() ?? '{}');
                $this->cache->remove('feed_xml_v2_' . $token . '_' . $etag . '_' . $configHash);
            }
        } catch (\Throwable $e) {
            // Ignore if feed or folder cannot be loaded
        }

        $this->cache->remove('feed_xml_' . $token);
    }

    public function getFile(string $token, int $fileId): ?File
    {
        $feed = $this->mapper->findByToken($token);
        $userFolder = $this->rootFolder->getUserFolder($feed->getUserId());
        $nodes = $userFolder->getById($fileId);
        if (empty($nodes)) {
            return null;
        }

        $file = $nodes[0];
        if (!($file instanceof File)) {
            return null;
        }

        $feedFolderNodes = $userFolder->getById($feed->getFolderId());
        if (empty($feedFolderNodes)) {
            return null;
        }

        $feedFolder = $feedFolderNodes[0];
        if (!($feedFolder instanceof Folder)) {
            return null;
        }

        $folderPrefix = rtrim($feedFolder->getPath(), '/') . '/';
        if (!str_starts_with($file->getPath(), $folderPrefix)) {
            return null;
        }

        $config = json_decode($feed->getConfiguration() ?? '{}', true);
        $flatten = filter_var($config['flatten'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        if (!$this->isEpisode($file, $feedFolder, $flatten)) {
            return null;
        }

        return $file;
    }

    public function getLogoFile(string $token): ?File
    {
        $feed = $this->mapper->findByToken($token);
        $config = json_decode($feed->getConfiguration() ?? '{}', true);
        $userFolder = $this->rootFolder->getUserFolder($feed->getUserId());

        if (!empty($config['logoFileId'])) {
            $nodes = $userFolder->getById($config['logoFileId']);
            if (!empty($nodes) && $nodes[0] instanceof File) {
                return $nodes[0];
            }
        }

        $folderNodes = $userFolder->getById($feed->getFolderId());
        if (!empty($folderNodes) && $folderNodes[0] instanceof Folder) {
            $feedFolder = $folderNodes[0];
            foreach (self::COVER_FILE_NAMES as $coverName) {
                if ($feedFolder->nodeExists($coverName)) {
                    $node = $feedFolder->get($coverName);
                    if ($node instanceof File) {
                        return $node;
                    }
                }
            }
        }

        return null;
    }

    public function getCover(string $token, int $fileId): ?array
    {
        $file = $this->getFile($token, $fileId);
        if (!$file) {
            return null;
        }
        return $this->metadataService->getCover($file);
    }

    public function cleanupOldEpisodes(Feed $feed): int
    {
        $userFolder = $this->rootFolder->getUserFolder($feed->getUserId());
        $folderNodes = $userFolder->getById($feed->getFolderId());
        if (empty($folderNodes) || !($folderNodes[0] instanceof Folder)) {
            return 0;
        }

        $folder = $folderNodes[0];
        $config = json_decode($feed->getConfiguration() ?? '{}', true);
        $autoremoveDays = (int) ($config['autoremoveDays'] ?? 0);
        if ($autoremoveDays <= 0) {
            return 0;
        }

        $flatten = filter_var($config['flatten'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $episodes = [];
        $this->scanFolder($folder, $episodes, $flatten, $folder);

        $now = time();
        $cutoff = $autoremoveDays * 86400;
        $deleted = 0;

        foreach ($episodes as $episode) {
            if (($now - $episode->getMtime()) > $cutoff) {
                try {
                    $episode->delete();
                    $deleted++;
                } catch (\Throwable $e) {
                    $this->logger->error('FolderCast: autoremove failed for ' . $episode->getPath() . ': ' . $e->getMessage(), [
                        'exception' => $e
                    ]);
                }
            }
        }

        if ($deleted > 0) {
            $this->clearCache($feed->getToken());
        }

        return $deleted;
    }

    public function scanFolder(Folder $folder, array &$items, bool $recursive, ?Folder $root = null, int $depth = 0): void
    {
        if ($depth > 20) {
            return;
        }
        $root = $root ?? $folder;
        $nodes = $folder->getDirectoryListing();

        foreach ($nodes as $node) {
            if ($node instanceof File) {
                if ($this->isEpisode($node, $root, $recursive)) {
                    $items[] = $node;
                }
            } elseif ($node instanceof Folder && $recursive) {
                if (!str_starts_with($node->getName(), '.')) {
                    try {
                        $this->scanFolder($node, $items, true, $root, $depth + 1);
                    } catch (\Throwable $e) {
                        $this->logger->warning('FolderCast: failed to scan directory ' . $node->getPath() . ': ' . $e->getMessage());
                    }
                }
            }
        }
    }

    private function xml(string $string): string
    {
        $cleaned = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $string) ?? '';
        return htmlspecialchars($cleaned, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function generateXml(Feed $feed, ?Folder $folder = null): string
    {
        if ($folder === null) {
            $userFolder = $this->rootFolder->getUserFolder($feed->getUserId());
            $folderNodes = $userFolder->getById($feed->getFolderId());
            if (empty($folderNodes) || !($folderNodes[0] instanceof Folder)) {
                throw new \RuntimeException('Folder not found');
            }
            $folder = $folderNodes[0];
        }

        $config = json_decode($feed->getConfiguration() ?? '{}', true);
        if (!is_array($config)) {
            $config = [];
        }
        $flatten = filter_var($config['flatten'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        // Default Metadata
        $channelTitle = $folder->getName();
        $channelDesc = 'Podcast from ' . $folder->getName();
        $channelAuthor = '';

        // Waterfall 1: podcast.json
        if ($folder->nodeExists('podcast.json')) {
            try {
                $jsonFile = $folder->get('podcast.json');
                if ($jsonFile instanceof File) {
                    $meta = json_decode($jsonFile->getContent(), true);
                    if (is_array($meta)) {
                        $channelTitle = !empty(trim((string)($meta['title'] ?? ''))) ? trim((string)$meta['title']) : $channelTitle;
                        $channelDesc = !empty(trim((string)($meta['description'] ?? ''))) ? trim((string)$meta['description']) : $channelDesc;
                        $channelAuthor = !empty(trim((string)($meta['author'] ?? ''))) ? trim((string)$meta['author']) : $channelAuthor;
                    }
                }
            } catch (\Throwable $e) {
                // Ignore corrupt podcast.json
            }
        }

        // Waterfall 2: Database Config (highest priority)
        if (!empty($config['title'])) {
            $channelTitle = (string)$config['title'];
        }
        if (!empty($config['description'])) {
            $channelDesc = (string)$config['description'];
        }
        if (!empty($config['author'])) {
            $channelAuthor = (string)$config['author'];
        }

        // Logo logic: uploaded logo > remote imageUrl > folder cover file
        $logoUrl = null;
        if (!empty($config['logoFileId'])) {
            $logoUrl = $this->urlGenerator->linkToRouteAbsolute('foldercast.feed.logo', [
                'token' => $feed->getToken(),
                'v' => $config['logoFileId'],
            ]);
        } elseif (!empty($config['imageUrl'])) {
            $logoUrl = $config['imageUrl'];
        } else {
            foreach (self::COVER_FILE_NAMES as $coverName) {
                if ($folder->nodeExists($coverName)) {
                    $coverNode = $folder->get($coverName);
                    $v = ($coverNode instanceof File) ? $coverNode->getMtime() : 1;
                    $logoUrl = $this->urlGenerator->linkToRouteAbsolute('foldercast.feed.logo', [
                        'token' => $feed->getToken(),
                        'v' => $v,
                    ]);
                    break;
                }
            }
        }

        // Scanning episodes
        $items = [];
        $this->scanFolder($folder, $items, $flatten, $folder);

        // Prepare items with metadata and timestamps for deterministic sorting
        $episodes = [];
        foreach ($items as $item) {
            $meta = $this->metadataService->getMetadata($item);

            $ts = null;
            if (!empty($meta['date'])) {
                $parsed = strtotime((string)$meta['date']);
                if ($parsed !== false) {
                    $ts = $parsed;
                }
            }
            if ($ts === null) {
                $ts = $item->getMtime();
            }

            $episodes[] = [
                'item' => $item,
                'meta' => $meta,
                'timestamp' => $ts,
            ];
        }

        // Sort episodes
        $sortBy = $config['sortBy'] ?? 'date_desc';
        if ($sortBy === 'date_asc') {
            usort($episodes, static fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
        } elseif ($sortBy === 'name') {
            usort($episodes, static fn($a, $b) => strnatcasecmp(
                $a['meta']['title'] ?? $a['item']->getName(),
                $b['meta']['title'] ?? $b['item']->getName()
            ));
        } else {
            // Default date_desc
            usort($episodes, static fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
        }

        $feedUrl = $this->urlGenerator->linkToRouteAbsolute('foldercast.feed.show', [
            'token' => $feed->getToken(),
        ]);

        $output = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $output .= '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $output .= '<channel>' . "\n";
        $output .= '  <title>' . $this->xml($channelTitle) . '</title>' . "\n";
        $output .= '  <description>' . $this->xml($channelDesc) . '</description>' . "\n";
        $output .= '  <link>' . $this->xml($feedUrl) . '</link>' . "\n";
        $output .= '  <language>' . $this->xml((string)($config['language'] ?? 'fr')) . '</language>' . "\n";
        $output .= '  <lastBuildDate>' . date('r') . '</lastBuildDate>' . "\n";
        $output .= '  <atom:link href="' . $this->xml($feedUrl) . '" rel="self" type="application/rss+xml"/>' . "\n";
        $output .= '  <itunes:explicit>false</itunes:explicit>' . "\n";
        $output .= '  <itunes:category text="Technology"/>' . "\n";

        if ($channelAuthor !== '') {
            $output .= '  <itunes:author>' . $this->xml($channelAuthor) . '</itunes:author>' . "\n";
        }

        if ($logoUrl) {
            $output .= '  <itunes:image href="' . $this->xml($logoUrl) . '"/>' . "\n";
            $output .= '  <image>' . "\n";
            $output .= '    <url>' . $this->xml($logoUrl) . '</url>' . "\n";
            $output .= '    <title>' . $this->xml($channelTitle) . '</title>' . "\n";
            $output .= '    <link>' . $this->xml($feedUrl) . '</link>' . "\n";
            $output .= '  </image>' . "\n";
        }

        foreach ($episodes as $ep) {
            /** @var File $item */
            $item = $ep['item'];
            $meta = $ep['meta'];
            $ts = $ep['timestamp'];

            $downloadUrl = $this->urlGenerator->linkToRouteAbsolute('foldercast.feed.download', [
                'token' => $feed->getToken(),
                'fileId' => $item->getId(),
            ]);

            $mime = $item->getMimeType();
            $ext = strtolower(pathinfo($item->getName(), PATHINFO_EXTENSION));
            if ($mime === 'application/octet-stream' || empty($mime)) {
                $mime = self::MIME_MAP[$ext] ?? 'audio/mpeg';
            }

            $output .= '  <item>' . "\n";
            $output .= '    <title>' . $this->xml((string)($meta['title'] ?? $item->getName())) . '</title>' . "\n";
            $output .= '    <enclosure url="' . $this->xml($downloadUrl) . '" length="' . $item->getSize() . '" type="' . $this->xml($mime) . '"/>' . "\n";
            $output .= '    <guid isPermaLink="false">foldercast-' . $feed->getId() . '-' . $item->getId() . '</guid>' . "\n";
            $output .= '    <pubDate>' . date('r', $ts) . '</pubDate>' . "\n";

            if (!empty($meta['has_cover'])) {
                $coverUrl = $this->urlGenerator->linkToRouteAbsolute('foldercast.feed.cover', [
                    'token' => $feed->getToken(),
                    'fileId' => $item->getId(),
                ]);
                $output .= '    <itunes:image href="' . $this->xml($coverUrl) . '"/>' . "\n";
            }

            if (!empty($meta['description'])) {
                $desc = (string)$meta['description'];
                $output .= '    <description>' . $this->xml($desc) . '</description>' . "\n";
                $output .= '    <itunes:summary>' . $this->xml($desc) . '</itunes:summary>' . "\n";
            }

            if (!empty($meta['artist'])) {
                $output .= '    <itunes:author>' . $this->xml((string)$meta['artist']) . '</itunes:author>' . "\n";
            }

            if (!empty($meta['url']) && filter_var($meta['url'], FILTER_VALIDATE_URL)) {
                $output .= '    <link>' . $this->xml((string)$meta['url']) . '</link>' . "\n";
            }

            if (!empty($meta['duration']) && (int)$meta['duration'] > 0) {
                $output .= '    <itunes:duration>' . ((int)$meta['duration']) . '</itunes:duration>' . "\n";
            }

            $output .= '  </item>' . "\n";
        }

        $output .= '</channel>' . "\n";
        $output .= '</rss>' . "\n";

        return $output;
    }
}

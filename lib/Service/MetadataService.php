<?php

declare(strict_types=1);

namespace OCA\FolderCast\Service;

use OCP\Files\File;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class MetadataService
{
    private LoggerInterface $logger;
    private ?ICache $cache;

    public function __construct(LoggerInterface $logger, ?ICacheFactory $cacheFactory = null)
    {
        $this->logger = $logger;
        $this->cache = $cacheFactory !== null ? $cacheFactory->createDistributed('foldercast_meta') : null;
    }

    public function getMetadata(File $file): array
    {
        $cacheKey = sprintf('meta_v1_%d_%d_%d', $file->getId(), $file->getMtime(), $file->getSize());
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = [
            'title' => $file->getName(),
            'artist' => null,
            'album' => null,
            'duration' => 0,
            'filesize' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'cover' => null,
            'has_cover' => false,
        ];

        $fileInfo = $this->analyzeFile($file);
        if (empty($fileInfo)) {
            if ($this->cache !== null) {
                $this->cache->set($cacheKey, $data, 2592000); // 30 days
            }
            return $data;
        }

        // Read from raw UTF-8 comments rather than HTML-encoded comments_html
        $comments = $fileInfo['comments'] ?? [];

        if (!empty($comments)) {
            $rawTitle = $this->extractFirstComment($comments, ['title']);
            $data['title'] = $rawTitle !== null ? $rawTitle : $file->getName();

            $data['artist'] = $this->extractFirstComment($comments, ['artist', 'composer', 'band']);
            $data['album'] = $this->extractFirstComment($comments, ['album']);

            // URL: Try standard comment or url
            $data['url'] = $this->extractFirstComment($comments, ['comment', 'url']);

            // Description: Try standard lyrics/description, fallback to USLT raw frame
            $rawDesc = $this->extractFirstComment($comments, ['unsynchronised_lyric', 'description', 'comment']);
            if ($rawDesc === null && !empty($fileInfo['id3v2']['USLT'][0]['data'])) {
                $rawDesc = trim((string)$fileInfo['id3v2']['USLT'][0]['data']);
                if ($rawDesc === '') {
                    $rawDesc = null;
                }
            }
            $data['description'] = $rawDesc;

            // Date logic
            $data['date'] = $this->extractFirstComment($comments, ['recording_time', 'date', 'year']);
        }

        if (isset($fileInfo['playtime_seconds'])) {
            $data['duration'] = (int) $fileInfo['playtime_seconds'];
        }

        if (!empty($fileInfo['comments']['picture'])) {
            $data['has_cover'] = true;
        } elseif (!empty($fileInfo['id3v2']['APIC'])) {
            $data['has_cover'] = true;
        }

        if ($this->cache !== null) {
            $this->cache->set($cacheKey, $data, 2592000); // 30 days
        }

        return $data;
    }

    public function getCover(File $file): ?array
    {
        $cacheKey = sprintf('cover_v1_%d_%d', $file->getId(), $file->getMtime());
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $fileInfo = $this->analyzeFile($file);
        if (empty($fileInfo)) {
            return null;
        }

        $cover = null;
        if (!empty($fileInfo['comments']['picture'])) {
            $picture = $fileInfo['comments']['picture'][0];
            $cover = [
                'data' => $picture['data'],
                'mime' => $picture['image_mime'] ?? 'image/jpeg',
            ];
        } elseif (!empty($fileInfo['id3v2']['APIC'])) {
            $picture = $fileInfo['id3v2']['APIC'][0];
            $cover = [
                'data' => $picture['data'],
                'mime' => $picture['image_mime'] ?? 'image/jpeg',
            ];
        }

        if ($cover !== null && $this->cache !== null && strlen($cover['data']) <= 512 * 1024) {
            $this->cache->set($cacheKey, $cover, 2592000); // 30 days
        }

        return $cover;
    }

    /**
     * @param array<string, mixed> $comments
     * @param string[] $keys
     */
    private function extractFirstComment(array $comments, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!isset($comments[$key])) {
                continue;
            }
            $val = $comments[$key];
            if (is_array($val)) {
                $val = $val[0] ?? '';
            }
            $trimmed = trim((string)$val);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }
        return null;
    }

    private function analyzeFile(File $file): array
    {
        if (!class_exists(\getID3::class)) {
            $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
            }
        }

        if (!class_exists(\getID3::class)) {
            $this->logger->warning('FolderCast: getID3 class not found. Metadata extraction skipped.');
            return [];
        }

        $localPath = null;
        $createdTempFile = false;
        $tempFile = null;

        try {
            // Attempt to get the local file path directly without copying
            try {
                $storage = $file->getStorage();
                if ($storage !== null && method_exists($storage, 'getLocalFile')) {
                    $path = $storage->getLocalFile($file->getInternalPath());
                    if (is_string($path) && file_exists($path)) {
                        $localPath = $path;
                    }
                }
            } catch (\Throwable $e) {
                $localPath = null;
            }

            if ($localPath === null) {
                // Fallback: Copy to unique temp file
                $createdTempFile = true;
                $tempFile = sys_get_temp_dir() . '/foldercast_' . $file->getId() . '_' . uniqid() . '.tmp';
                $source = null;
                $dest = null;
                try {
                    $source = $file->fopen('rb');
                    $dest = fopen($tempFile, 'wb');
                    if ($source !== false && $dest !== false) {
                        stream_copy_to_stream($source, $dest);
                    }
                } finally {
                    if (is_resource($source)) {
                        fclose($source);
                    }
                    if (is_resource($dest)) {
                        fclose($dest);
                    }
                }
                $localPath = $tempFile;
            }

            if (!file_exists($localPath)) {
                return [];
            }

            $getID3 = new \getID3();
            $getID3->option_tag_id3v1 = true;
            $getID3->option_tag_id3v2 = true;
            $getID3->option_tag_lyrics3 = false;
            $getID3->option_tag_apetag = true;
            $getID3->option_tags_process = true;
            $getID3->option_tags_html = false; // Disabling HTML encoding saves CPU and RAM
            $getID3->option_extra_info = false;
            $getID3->option_save_attachments = true;

            $fileInfo = $getID3->analyze($localPath);
            \getid3_lib::CopyTagsToComments($fileInfo);

            return is_array($fileInfo) ? $fileInfo : [];
        } catch (\Throwable $e) {
            $this->logger->error('Error parsing ID3 tags: ' . $e->getMessage(), ['exception' => $e]);
            return [];
        } finally {
            // Only unlink temp files created by this service, never user storage files!
            if ($createdTempFile && $tempFile !== null && file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace OCA\FolderCast\Service;

use OCP\Files\File;
use Psr\Log\LoggerInterface;

class MetadataService
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getMetadata(File $file): array
    {
        $data = [
            'title' => $file->getName(),
            'artist' => null,
            'album' => null,
            'duration' => 0,
            'filesize' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'cover' => null, // Binary data or boolean?
        ];

        if (!class_exists(\getID3::class)) {
            // Try to load composer autoloader if not already loaded
            // Internal vendor folder (from make)
            $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
            }
        }

        if (!class_exists(\getID3::class)) {
            $this->logger->warning('FolderCast: getID3 class not found. Metadata extraction skipped.');
            return $data;
        }

        try {
            // getID3 requires a local file path.
            // IMPORTANT: getLocalFile() may return a SHARED temp path that gets overwritten.
            // We must copy the content to a unique temp file for reliable analysis.
            $localPath = null;
            $tempFile = null;

            try {
                // Create a unique temp file for this specific analysis
                $tempFile = sys_get_temp_dir() . '/foldercast_' . $file->getId() . '_' . uniqid() . '.tmp';
                $content = $file->getContent();
                file_put_contents($tempFile, $content);
                $localPath = $tempFile;
            } catch (\Exception $e) {
                $this->logger->warning('Could not create temp file for ID3 analysis: ' . $e->getMessage());
                return $data;
            }

            $getID3 = new \getID3();
            $fileInfo = $getID3->analyze($localPath);
            \getid3_lib::CopyTagsToComments($fileInfo);

            if (isset($fileInfo['comments_html'])) {
                $comments = $fileInfo['comments_html'];

                $data['title'] = $comments['title'][0] ?? $file->getName();
                $data['artist'] = $comments['artist'][0] ?? null;
                $data['album'] = $comments['album'][0] ?? null;

                // URL: Try standard comment, fallback to COMM frame in id3v2 if needed
                $data['url'] = $comments['comment'][0] ?? null;

                // Description: Try standard lyrics, fallback to USLT raw frame
                $data['description'] = $comments['unsynchronised_lyric'][0] ?? ($comments['description'][0] ?? null);
                if (empty($data['description']) && !empty($fileInfo['id3v2']['USLT'][0]['data'])) {
                    $data['description'] = $fileInfo['id3v2']['USLT'][0]['data'];
                }

                // Date logic
                $data['date'] = $comments['recording_time'][0] ?? ($comments['date'][0] ?? ($comments['year'][0] ?? null));
            }

            if (isset($fileInfo['playtime_seconds'])) {
                $data['duration'] = (int) $fileInfo['playtime_seconds'];
            }

            // Detect cover using specific logic if needed, or getID3's detection
            // getID3 puts entries in 'comments' -> 'picture'
            if (!empty($fileInfo['comments']['picture'])) {
                $data['has_cover'] = true;
                // We don't extract the image blob here to avoid memory issues, just know it exists.
            }

        } catch (\Throwable $e) {
            $this->logger->error('Error parsing ID3 tags: ' . $e->getMessage());
        } finally {
            // Clean up temp file
            if (isset($tempFile) && file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }

        return $data;
    }
}

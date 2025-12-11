<?php

declare(strict_types=1);

namespace OCA\FolderCast\Service;

use OCP\Files\File;
use OCP\Files\Node;
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
            // Fallback if dependency is missing
            return $data;
        }

        try {
            // getID3 requires a local file path.
            // getLocalFile() ensures we have a local copy (e.g. from object store)
            // CAUTION: This can be slow for large files on external storage.
            $localPath = null;
            try {
                // Try to get local path without downloading if possible (e.g. local storage)
                // But Node doesn't expose underlying storage path easily safely.
                // For now rely on getLocalFile which might be expensive.
                // Optimization: We could read just the header bytes?
                // getID3 has an option to analyze from stream/string, but it's complex.
                $localPath = $file->getLocalFile();
            } catch (\Exception $e) {
                $this->logger->warning('Could not get local file for ID3 analysis: ' . $e->getMessage());
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
        }

        return $data;
    }
}

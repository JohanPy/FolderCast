<?php

declare(strict_types=1);

namespace OCA\FolderCast\Controller;

use OCA\FolderCast\Db\Feed;
use OCA\FolderCast\Db\FeedMapper;
use OCA\FolderCast\Service\FeedService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class ApiController extends Controller
{
	private const ALLOWED_LOGO_MIMES = [
		'image/jpeg' => 'jpg',
		'image/png' => 'png',
		'image/webp' => 'webp',
		'image/gif' => 'gif',
	];

	private FeedMapper $mapper;
	private IUserSession $userSession;
	private ISecureRandom $secureRandom;
	private IRootFolder $rootFolder;
	private FeedService $feedService;
	private ?LoggerInterface $logger;
	private ?IFilenameValidator $filenameValidator;

	public function __construct(
		string $appName,
		IRequest $request,
		FeedMapper $mapper,
		IUserSession $userSession,
		ISecureRandom $secureRandom,
		IRootFolder $rootFolder,
		FeedService $feedService,
		?LoggerInterface $logger = null,
		?IFilenameValidator $filenameValidator = null
	) {
		parent::__construct($appName, $request);
		$this->mapper = $mapper;
		$this->userSession = $userSession;
		$this->secureRandom = $secureRandom;
		$this->rootFolder = $rootFolder;
		$this->feedService = $feedService;
		$this->logger = $logger;
		$this->filenameValidator = $filenameValidator;
	}

	#[NoAdminRequired]
	public function index(): DataResponse
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		$feeds = $this->mapper->findByUserId($user->getUID());

		$enrichedFeeds = [];
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());

		foreach ($feeds as $feed) {
			$data = $feed->jsonSerialize();
			$data['path'] = 'Unknown/Deleted';

			try {
				$nodes = $userFolder->getById($feed->getFolderId());
				if (!empty($nodes)) {
					$folder = $nodes[0];
					if ($folder instanceof Folder) {
						$fullPath = $folder->getPath();
						$userPath = $userFolder->getPath();
						if (str_starts_with($fullPath, $userPath)) {
							$relativePath = substr($fullPath, strlen($userPath));
							$data['path'] = ltrim($relativePath, '/');
						} else {
							$data['path'] = $folder->getName();
						}
					}
				}
			} catch (\Throwable $e) {
				// Ignore errors resolving path
			}
			$enrichedFeeds[] = $data;
		}

		return new DataResponse($enrichedFeeds);
	}

	private function sanitizeConfiguration(array $configuration): array
	{
		$sanitized = [];
		if (isset($configuration['title']) && is_string($configuration['title'])) {
			$sanitized['title'] = mb_substr(trim($configuration['title']), 0, 4000);
		}
		if (isset($configuration['description']) && is_string($configuration['description'])) {
			$sanitized['description'] = mb_substr(trim($configuration['description']), 0, 4000);
		}
		if (isset($configuration['author']) && is_string($configuration['author'])) {
			$sanitized['author'] = mb_substr(trim($configuration['author']), 0, 4000);
		}
		if (array_key_exists('imageUrl', $configuration)) {
			$url = trim((string) $configuration['imageUrl']);
			if ($url === '') {
				$sanitized['imageUrl'] = '';
			} elseif (filter_var($url, FILTER_VALIDATE_URL)) {
				$scheme = parse_url($url, PHP_URL_SCHEME);
				if (in_array(strtolower((string)$scheme), ['http', 'https'], true)) {
					$sanitized['imageUrl'] = $url;
				}
			}
		}
		if (isset($configuration['autoremoveDays'])) {
			$days = (int) $configuration['autoremoveDays'];
			if ($days >= 0 && $days <= 3650) {
				$sanitized['autoremoveDays'] = $days;
			}
		}
		if (isset($configuration['flatten'])) {
			$sanitized['flatten'] = filter_var($configuration['flatten'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
		}
		if (isset($configuration['sortBy']) && in_array($configuration['sortBy'], ['date_desc', 'date_asc', 'name'], true)) {
			$sanitized['sortBy'] = $configuration['sortBy'];
		}

		return $sanitized;
	}

	#[NoAdminRequired]
	public function create(?int $folderId = null, ?array $config = null, ?string $podcastName = null): DataResponse
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());

		// Scenario 1: Creating a NEW podcast folder
		if ($podcastName !== null) {
			$podcastName = trim($podcastName);
			if ($podcastName === '' || mb_strlen($podcastName) > 200) {
				return new DataResponse(['error' => 'Invalid podcast name'], Http::STATUS_BAD_REQUEST);
			}

			if ($this->filenameValidator !== null) {
				try {
					$this->filenameValidator->validateFilename($podcastName);
				} catch (\Throwable $e) {
					return new DataResponse(['error' => 'Invalid podcast name: ' . $e->getMessage()], Http::STATUS_BAD_REQUEST);
				}
			} else {
				if (str_contains($podcastName, '/') || str_contains($podcastName, '\\') || str_contains($podcastName, '..') || str_contains($podcastName, "\0")) {
					return new DataResponse(['error' => 'Invalid podcast name characters'], Http::STATUS_BAD_REQUEST);
				}
			}

			if (!$folderId) {
				$folderName = 'Podcasts';
				if (!$userFolder->nodeExists($folderName)) {
					$userFolder->newFolder($folderName);
				}
				$podcastsNode = $userFolder->get($folderName);
				if (!($podcastsNode instanceof Folder)) {
					return new DataResponse(['error' => 'Podcasts folder conflict'], Http::STATUS_BAD_REQUEST);
				}

				if (!$podcastsNode->nodeExists($podcastName)) {
					$podcastsNode->newFolder($podcastName);
				}
				$targetNode = $podcastsNode->get($podcastName);
				if (!($targetNode instanceof Folder)) {
					return new DataResponse(['error' => 'Target podcast is not a folder'], Http::STATUS_BAD_REQUEST);
				}
				$folderId = $targetNode->getId();
			}
		}

		if (!$folderId) {
			return new DataResponse(['error' => 'Missing folderId or podcastName'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$nodes = $userFolder->getById($folderId);
			if (empty($nodes)) {
				return new DataResponse(['error' => 'Folder not found or access denied'], Http::STATUS_NOT_FOUND);
			}
			$targetNode = $nodes[0];
			if (!($targetNode instanceof Folder)) {
				return new DataResponse(['error' => 'Target is not a folder'], Http::STATUS_BAD_REQUEST);
			}
			if (!$targetNode->isReadable()) {
				return new DataResponse(['error' => 'Folder is not readable'], Http::STATUS_BAD_REQUEST);
			}
		} catch (\Throwable $e) {
			return new DataResponse(['error' => 'Invalid folder'], Http::STATUS_BAD_REQUEST);
		}

		// Check for existing feed (idempotent duplicate handling)
		$existingFeed = $this->mapper->findByFolderIdAndUserId($folderId, $user->getUID());
		if ($existingFeed !== null) {
			$data = $existingFeed->jsonSerialize();
			$data['existing'] = true;
			return new DataResponse($data, Http::STATUS_OK);
		}

		$token = $this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);

		$feed = new Feed();
		$feed->setUserId($user->getUID());
		$feed->setFolderId($folderId);
		$feed->setToken($token);
		if (is_array($config) && !empty($config)) {
			$feed->setConfiguration(json_encode($this->sanitizeConfiguration($config)));
		}

		$this->mapper->insert($feed);
		return new DataResponse($feed);
	}

	#[NoAdminRequired]
	public function update(int $id, array $configuration): DataResponse
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$feed = $this->mapper->find($id);
		} catch (\Throwable $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if ($feed->getUserId() !== $user->getUID()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		$currentConfig = json_decode($feed->getConfiguration() ?? '{}', true);
		if (!is_array($currentConfig)) {
			$currentConfig = [];
		}

		$sanitized = $this->sanitizeConfiguration($configuration);
		$newConfig = array_merge($currentConfig, $sanitized);
		$feed->setConfiguration(json_encode($newConfig));

		$this->mapper->update($feed);
		$this->feedService->clearCache($feed->getToken());
		return new DataResponse($feed);
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$feed = $this->mapper->find($id);
		} catch (\Throwable $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if ($feed->getUserId() !== $user->getUID()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		$this->mapper->delete($feed);
		$this->feedService->clearCache($feed->getToken());
		return new DataResponse([]);
	}

	#[NoAdminRequired]
	public function uploadLogo(int $id): DataResponse
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$feed = $this->mapper->find($id);
		} catch (\Throwable $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if ($feed->getUserId() !== $user->getUID()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		$uploadedFile = $this->request->getUploadedFile('logo');
		if (!is_array($uploadedFile) || ($uploadedFile['error'] ?? null) !== UPLOAD_ERR_OK) {
			return new DataResponse(['error' => 'No file uploaded or upload error'], Http::STATUS_BAD_REQUEST);
		}

		$tmpName = $uploadedFile['tmp_name'] ?? '';
		$isUploaded = is_uploaded_file($tmpName);
		if (!$isUploaded && (php_sapi_name() !== 'cli' || !file_exists($tmpName))) {
			return new DataResponse(['error' => 'Invalid uploaded file'], Http::STATUS_BAD_REQUEST);
		}

		$size = filesize($tmpName);
		if ($size === false || $size > 5 * 1024 * 1024) {
			return new DataResponse(['error' => 'File too large (max 5MB)'], Http::STATUS_BAD_REQUEST);
		}

		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		$mime = finfo_file($finfo, $tmpName);
		finfo_close($finfo);

		if (!isset(self::ALLOWED_LOGO_MIMES[$mime])) {
			return new DataResponse(['error' => 'Unsupported image format (JPEG, PNG, WebP, GIF only)'], Http::STATUS_BAD_REQUEST);
		}

		$ext = self::ALLOWED_LOGO_MIMES[$mime];
		$targetName = '_logo.' . $ext;

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$nodes = $userFolder->getById($feed->getFolderId());
		if (empty($nodes)) {
			return new DataResponse(['error' => 'Feed folder not found'], Http::STATUS_NOT_FOUND);
		}
		$folder = $nodes[0];
		if (!($folder instanceof Folder)) {
			return new DataResponse(['error' => 'Invalid folder'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$stream = null;
		try {
			// Clean up any existing logo files
			foreach ($folder->getDirectoryListing() as $node) {
				if ($node instanceof File && str_starts_with($node->getName(), '_logo.')) {
					$node->delete();
				}
			}

			$file = $folder->newFile($targetName);
			$stream = fopen($tmpName, 'rb');
			$file->putContent($stream);

			// Update config with logoFileId
			$config = json_decode($feed->getConfiguration() ?? '{}', true);
			if (!is_array($config)) {
				$config = [];
			}
			$config['logoFileId'] = $file->getId();
			$feed->setConfiguration(json_encode($config));
			$this->mapper->update($feed);

			$this->feedService->clearCache($feed->getToken());

			return new DataResponse(['fileId' => $file->getId()]);
		} catch (\Throwable $e) {
			$this->logger?->error('Error uploading logo: ' . $e->getMessage(), [
				'feedId' => $id,
				'exception' => $e,
			]);
			return new DataResponse(['error' => 'Failed to upload logo'], Http::STATUS_INTERNAL_SERVER_ERROR);
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
	}
}

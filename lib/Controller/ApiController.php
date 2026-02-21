<?php

declare(strict_types=1);

namespace OCA\FolderCast\Controller;

use OCA\FolderCast\Db\Feed;
use OCA\FolderCast\Db\FeedMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;

use OCA\FolderCast\Service\FeedService;

class ApiController extends Controller
{
	private FeedMapper $mapper;
	private IUserSession $userSession;
	private ISecureRandom $secureRandom;
	private IRootFolder $rootFolder;
	private FeedService $feedService;

	public function __construct(
		string $appName,
		IRequest $request,
		FeedMapper $mapper,
		IUserSession $userSession,
		ISecureRandom $secureRandom,
		IRootFolder $rootFolder,
		FeedService $feedService
	) {
		parent::__construct($appName, $request);
		$this->mapper = $mapper;
		$this->userSession = $userSession;
		$this->secureRandom = $secureRandom;
		$this->rootFolder = $rootFolder;
		$this->feedService = $feedService;
	}

	/**
	 * @return DataResponse
	 */
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
						// Get path relative to user folder
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
			} catch (\Exception $e) {
				// Ignore errors resolving path
			}
			$enrichedFeeds[] = $data;
		}

		return new DataResponse($enrichedFeeds);
	}

	/**
	 * @param int $folderId
	 * @param array|null $config
	 * @return DataResponse
	 */
	public function create(?int $folderId = null, ?array $config = null, ?string $podcastName = null): DataResponse
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());

		// Scenario 1: Creating a NEW podcast folder
		if ($podcastName && !$folderId) {
			$folderName = 'Podcasts';
			if (!$userFolder->nodeExists($folderName)) {
				$userFolder->newFolder($folderName);
			}
			$podcastsFolder = $userFolder->get($folderName);

			if (!$podcastsFolder->nodeExists($podcastName)) {
				$podcastsFolder->newFolder($podcastName);
			}
			$targetFolder = $podcastsFolder->get($podcastName);
			$folderId = $targetFolder->getId();
		}

		if (!$folderId) {
			return new DataResponse(['error' => 'Missing folderId or podcastName'], Http::STATUS_BAD_REQUEST);
		}

		// Basic validation check
		// Check if user has access to folderId by trying to get it from their root
		try {
			$nodes = $userFolder->getById($folderId);
			if (empty($nodes)) {
				return new DataResponse(['error' => 'Folder not found or access denied'], Http::STATUS_NOT_FOUND);
			}
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Invalid folder'], Http::STATUS_BAD_REQUEST);
		}

		$token = $this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);

		$feed = new Feed();
		$feed->setUserId($user->getUID());
		$feed->setFolderId($folderId);
		$feed->setToken($token);
		if ($config) {
			$feed->setConfiguration(json_encode($config));
		}

		$this->mapper->insert($feed);
		return new DataResponse($feed);
	}

	/**
	 * @param int $id
	 * @param array $configuration
	 * @return DataResponse
	 */
	public function update(int $id, array $configuration): DataResponse
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$feed = $this->mapper->find($id);
		} catch (\Exception $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if ($feed->getUserId() !== $user->getUID()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		// Merge existing config with new config
		$currentConfig = json_decode($feed->getConfiguration() ?? '{}', true);
		$newConfig = array_merge($currentConfig, $configuration);
		$feed->setConfiguration(json_encode($newConfig));

		$this->mapper->update($feed);
		$this->feedService->clearCache($feed->getToken());
		return new DataResponse($feed);
	}

	public function destroy(int $id): DataResponse
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$feed = $this->mapper->find($id);
		} catch (\Exception $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if ($feed->getUserId() !== $user->getUID()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		$this->mapper->delete($feed);
		$this->feedService->clearCache($feed->getToken());
		return new DataResponse([]);
	}

	/**
	 * @param int $id
	 * @return DataResponse
	 */
	public function uploadLogo(int $id): DataResponse
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$feed = $this->mapper->find($id);
		} catch (\Exception $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if ($feed->getUserId() !== $user->getUID()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		$uploadedFile = $this->request->getUploadedFile('logo');
		if (!$uploadedFile) {
			return new DataResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$nodes = $userFolder->getById($feed->getFolderId());
		if (empty($nodes)) {
			return new DataResponse(['error' => 'Feed folder not found'], Http::STATUS_NOT_FOUND);
		}
		$folder = $nodes[0];
		if (!($folder instanceof Folder)) {
			return new DataResponse(['error' => 'Invalid folder'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// Save file as hidden file inside the folder
		// We use a fixed name pattern or unique one? Let's use _logo.<ext>
		// Check file extension
		$originalName = $uploadedFile['name'] ?? 'logo.jpg';
		$ext = pathinfo($originalName, PATHINFO_EXTENSION);
		$targetName = '_logo.' . $ext;

		try {
			if ($folder->nodeExists($targetName)) {
				$file = $folder->get($targetName);
				if ($file instanceof \OCP\Files\File) {
					$file->delete();
				}
			}
			$file = $folder->newFile($targetName);
			$stream = fopen($uploadedFile['tmp_name'], 'rb');
			$file->putContent($stream);
			if (is_resource($stream)) {
				fclose($stream);
			}

			// Update config
			$config = json_decode($feed->getConfiguration() ?? '{}', true);
			$config['logoFileId'] = $file->getId();
			$feed->setConfiguration(json_encode($config));
			$this->mapper->update($feed);

			$this->feedService->clearCache($feed->getToken());

			return new DataResponse(['fileId' => $file->getId()]);
		} catch (\Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}

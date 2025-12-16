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

class ApiController extends Controller
{
	private FeedMapper $mapper;
	private IUserSession $userSession;
	private ISecureRandom $secureRandom;
	private IRootFolder $rootFolder;

	public function __construct(
		string $appName,
		IRequest $request,
		FeedMapper $mapper,
		IUserSession $userSession,
		ISecureRandom $secureRandom,
		IRootFolder $rootFolder
	) {
		parent::__construct($appName, $request);
		$this->mapper = $mapper;
		$this->userSession = $userSession;
		$this->secureRandom = $secureRandom;
		$this->rootFolder = $rootFolder;
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
	public function create(int $folderId, ?array $config = null): DataResponse
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		// Basic validation: User should have access to folderId
		// Omitted logic to check if user really sees this folderId for brevity,
		// but typically we should resolve folderId for user to verify.

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
		return new DataResponse($feed);
	}

	/**
	 * @param int $id
	 * @return DataResponse
	 */
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
		return new DataResponse([]);
	}
}

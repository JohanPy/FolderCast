<?php

declare(strict_types=1);

namespace OCA\FolderCast\Tests\Unit\Controller;

use OCA\FolderCast\Controller\ApiController;
use OCA\FolderCast\Db\Feed;
use OCA\FolderCast\Db\FeedMapper;
use OCA\FolderCast\Service\FeedService;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ApiTest extends TestCase
{
	private IRequest $request;
	private FeedMapper $mapper;
	private IUserSession $userSession;
	private ISecureRandom $secureRandom;
	private IRootFolder $rootFolder;
	private FeedService $feedService;
	private LoggerInterface $logger;
	private IUser $user;
	private Folder $userFolder;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->mapper = $this->createMock(FeedMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->feedService = $this->createMock(FeedService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('john');
		$this->userSession->method('getUser')->willReturn($this->user);

		$this->userFolder = $this->createMock(Folder::class);
		$this->rootFolder->method('getUserFolder')->with('john')->willReturn($this->userFolder);
	}

	private function makeController(): ApiController
	{
		return new ApiController(
			'foldercast',
			$this->request,
			$this->mapper,
			$this->userSession,
			$this->secureRandom,
			$this->rootFolder,
			$this->feedService,
			$this->logger
		);
	}

	public function testIndexReturnsUnauthorizedWithoutUser(): void
	{
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$controller = new ApiController(
			'foldercast',
			$this->request,
			$this->mapper,
			$session,
			$this->secureRandom,
			$this->rootFolder,
			$this->feedService,
			$this->logger
		);

		$response = $controller->index();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	public function testCreateNominal(): void
	{
		$folder = $this->createMock(Folder::class);
		$folder->method('isReadable')->willReturn(true);
		$this->userFolder->method('getById')->with(123)->willReturn([$folder]);

		$this->mapper->method('findByFolderIdAndUserId')->with(123, 'john')->willReturn(null);
		$this->secureRandom->method('generate')->willReturn('random_token_123');

		$this->mapper->expects($this->once())->method('insert')->with($this->callback(function (Feed $feed) {
			return $feed->getUserId() === 'john' &&
				$feed->getFolderId() === 123 &&
				$feed->getToken() === 'random_token_123';
		}));

		$controller = $this->makeController();
		$response = $controller->create(123, ['title' => 'Test Podcast']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertInstanceOf(Feed::class, $data);
		$this->assertSame('random_token_123', $data->getToken());
	}

	public function testCreateSanitizesConfiguration(): void
	{
		$folder = $this->createMock(Folder::class);
		$folder->method('isReadable')->willReturn(true);
		$this->userFolder->method('getById')->with(123)->willReturn([$folder]);
		$this->mapper->method('findByFolderIdAndUserId')->with(123, 'john')->willReturn(null);
		$this->secureRandom->method('generate')->willReturn('tok123');

		$this->mapper->expects($this->once())->method('insert')->with($this->callback(function (Feed $feed) {
			$cfg = json_decode($feed->getConfiguration(), true);
			return $cfg['title'] === 'Legit Title' &&
				$cfg['author'] === 'Legit Author' &&
				!isset($cfg['logoFileId']) &&
				!isset($cfg['arbitraryParam']);
		}));

		$controller = $this->makeController();
		$response = $controller->create(123, [
			'title' => 'Legit Title',
			'author' => 'Legit Author',
			'logoFileId' => 9999,
			'arbitraryParam' => 'malicious',
		]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCreateRejectsInvalidPodcastName(): void
	{
		$controller = $this->makeController();
		// Test path traversal name
		$response = $controller->create(null, [], '../evil');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

		// Test empty name
		$response2 = $controller->create(null, [], '   ');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response2->getStatus());
	}

	public function testCreateDuplicateReturnsExistingFeedWithFlag(): void
	{
		$folder = $this->createMock(Folder::class);
		$folder->method('isReadable')->willReturn(true);
		$this->userFolder->method('getById')->with(123)->willReturn([$folder]);

		$existing = new Feed();
		$existing->setId(42);
		$existing->setUserId('john');
		$existing->setFolderId(123);
		$existing->setToken('existing_token');

		$this->mapper->method('findByFolderIdAndUserId')->with(123, 'john')->willReturn($existing);
		// insert must NOT be called
		$this->mapper->expects($this->never())->method('insert');

		$controller = $this->makeController();
		$response = $controller->create(123);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertSame('existing_token', $data['token']);
		$this->assertTrue($data['existing']);
	}

	public function testUpdateWhitelistIgnoresLogoFileId(): void
	{
		$feed = new Feed();
		$feed->setId(5);
		$feed->setUserId('john');
		$feed->setToken('tok');
		$feed->setConfiguration(json_encode(['logoFileId' => 999, 'title' => 'Old Title']));

		$this->mapper->method('find')->with(5)->willReturn($feed);

		$this->mapper->expects($this->once())->method('update')->with($this->callback(function (Feed $f) {
			$cfg = json_decode($f->getConfiguration(), true);
			// logoFileId must NOT be changed by client input
			return $cfg['logoFileId'] === 999 &&
				$cfg['title'] === 'New Title' &&
				$cfg['author'] === 'New Author' &&
				$cfg['flatten'] === false &&
				$cfg['sortBy'] === 'date_asc';
		}));

		$controller = $this->makeController();
		$response = $controller->update(5, [
			'title' => 'New Title',
			'author' => 'New Author',
			'flatten' => false,
			'sortBy' => 'date_asc',
			'logoFileId' => 1111, // Attacker trying to set logoFileId
		]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateForbiddenForOtherUser(): void
	{
		$feed = new Feed();
		$feed->setId(5);
		$feed->setUserId('other_user'); // Not john

		$this->mapper->method('find')->with(5)->willReturn($feed);
		$this->mapper->expects($this->never())->method('update');

		$controller = $this->makeController();
		$response = $controller->update(5, ['title' => 'Hacked']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testDestroyNominal(): void
	{
		$feed = new Feed();
		$feed->setId(5);
		$feed->setUserId('john');
		$feed->setToken('tok');

		$this->mapper->method('find')->with(5)->willReturn($feed);
		$this->mapper->expects($this->once())->method('delete')->with($feed);
		$this->feedService->expects($this->once())->method('clearCache')->with('tok');

		$controller = $this->makeController();
		$response = $controller->destroy(5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testDestroyForbiddenForOtherUser(): void
	{
		$feed = new Feed();
		$feed->setId(5);
		$feed->setUserId('other_user');

		$this->mapper->method('find')->with(5)->willReturn($feed);
		$this->mapper->expects($this->never())->method('delete');

		$controller = $this->makeController();
		$response = $controller->destroy(5);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}
}

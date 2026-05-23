<?php

declare(strict_types=1);

namespace Controller;

use OCA\FolderCast\Controller\ApiController;
use OCA\FolderCast\Db\FeedMapper;
use OCA\FolderCast\Service\FeedService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase {
	private function requireNextcloudRuntimeInterfaces(): void {
		$required = [
			'\\OCP\\IUserSession',
			'\\OCP\\Security\\ISecureRandom',
			'\\OCP\\Files\\IRootFolder',
		];

		foreach ($required as $fqcn) {
			if (!interface_exists($fqcn)) {
				$this->markTestSkipped('Requires Nextcloud runtime interface: ' . $fqcn);
			}
		}
	}

	private function makeController(object $userSession): ApiController {
		return new ApiController(
			'foldercast',
			$this->createMock(IRequest::class),
			$this->createMock(FeedMapper::class),
			$userSession,
			$this->createMock('\\OCP\\Security\\ISecureRandom'),
			$this->createMock('\\OCP\\Files\\IRootFolder'),
			$this->createMock(FeedService::class)
		);
	}

	public function testIndexReturnsUnauthorizedWithoutUser(): void {
		$this->requireNextcloudRuntimeInterfaces();
		$userSession = $this->createMock('\\OCP\\IUserSession');
		$userSession->method('getUser')->willReturn(null);

		$controller = $this->makeController($userSession);
		$response = $controller->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	public function testCreateReturnsUnauthorizedWithoutUser(): void {
		$this->requireNextcloudRuntimeInterfaces();
		$userSession = $this->createMock('\\OCP\\IUserSession');
		$userSession->method('getUser')->willReturn(null);

		$controller = $this->makeController($userSession);
		$response = $controller->create(123, []);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $response->getData());
	}
}

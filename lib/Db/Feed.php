<?php

declare(strict_types=1);

namespace OCA\FolderCast\Db;

use OCP\AppFramework\Db\Entity;

class Feed extends Entity implements \JsonSerializable
{

    protected $folderId;
    protected $userId;
    protected $token;
    protected $configuration;
    protected $metadataOverride;

    public function __construct()
    {
        $this->addType('folderId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('token', 'string');
        $this->addType('configuration', 'string');
        $this->addType('metadataOverride', 'string');
    }

    public function setFolderId(int $folderId): void
    {
        $this->folderId = $folderId;
        $this->markFieldUpdated('folderId');
    }

    public function getFolderId(): int
    {
        return (int) $this->folderId;
    }

    public function setUserId(string $userId): void
    {
        $this->userId = $userId;
        $this->markFieldUpdated('userId');
    }

    public function getUserId(): string
    {
        return (string) $this->userId;
    }

    public function setToken(string $token): void
    {
        $this->token = $token;
        $this->markFieldUpdated('token');
    }

    public function getToken(): string
    {
        return (string) $this->token;
    }

    public function setConfiguration(?string $configuration): void
    {
        $this->configuration = $configuration;
        $this->markFieldUpdated('configuration');
    }

    public function getConfiguration(): ?string
    {
        return $this->configuration;
    }

    public function setMetadataOverride(?string $metadataOverride): void
    {
        $this->metadataOverride = $metadataOverride;
        $this->markFieldUpdated('metadataOverride');
    }

    public function getMetadataOverride(): ?string
    {
        return $this->metadataOverride;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'folderId' => $this->folderId,
            'userId' => $this->userId,
            'token' => $this->token,
            'configuration' => $this->configuration,
            'metadataOverride' => $this->metadataOverride
        ];
    }
}

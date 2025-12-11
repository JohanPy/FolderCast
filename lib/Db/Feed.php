<?php

declare(strict_types=1);

namespace OCA\FolderCast\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int|null getFolderId()
 * @method void setFolderId(int $folderId)
 * @method string|null getUserId()
 * @method void setUserId(string $userId)
 * @method string|null getToken()
 * @method void setToken(string $token)
 * @method string|null getConfiguration()
 * @method void setConfiguration(string $configuration)
 * @method string|null getMetadataOverride()
 * @method void setMetadataOverride(string $metadataOverride)
 */
class Feed extends Entity
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
}

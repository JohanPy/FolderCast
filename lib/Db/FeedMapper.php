<?php

declare(strict_types=1);

namespace OCA\FolderCast\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * @template-extends QBMapper<Feed>
 */
class FeedMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'foldercast_feeds', Feed::class);
    }

    public function find(int $id): Feed
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('foldercast_feeds')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        return $this->findEntity($qb);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findByToken(string $token): Feed
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('foldercast_feeds')
            ->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));

        return $this->findEntity($qb);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findByFolderId(int $folderId): Feed
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('foldercast_feeds')
            ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));

        return $this->findEntity($qb);
    }

    public function findByUserId(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('foldercast_feeds')
            ->where(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId))
            );

        return $this->findEntities($qb);
    }
}

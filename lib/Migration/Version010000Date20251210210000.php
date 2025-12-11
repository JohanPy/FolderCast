<?php

declare(strict_types=1);

namespace OCA\FolderCast\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010000Date20251210210000 extends SimpleMigrationStep
{
    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('foldercast_feeds')) {
            $table = $schema->createTable('foldercast_feeds');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('folder_id', 'integer', [
                'notnull' => true,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('token', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('configuration', 'text', [
                'notnull' => false,
            ]);
            $table->addColumn('metadata_override', 'text', [
                'notnull' => false,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['token'], 'foldercast_token_idx');
            $table->addIndex(['folder_id'], 'foldercast_folder_idx');
        }

        return $schema;
    }
}

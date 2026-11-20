<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds static_site.updated_at; existing entries get the time of the migration.
 */
final class Version20261121120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add static_site.updated_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE static_site ADD updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT now() NOT NULL
        SQL);
        // The default only fills the existing rows; the mapping has none.
        $this->addSql(<<<'SQL'
            ALTER TABLE static_site ALTER updated_at DROP DEFAULT
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE static_site DROP updated_at
        SQL);
    }
}

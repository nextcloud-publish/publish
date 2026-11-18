<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates static_site, one entry per site, with a unique slug.
 */
final class Version20261118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create static_site';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE static_site (
              id UUID NOT NULL,
              slug VARCHAR(128) NOT NULL,
              status VARCHAR(16) NOT NULL,
              build_id UUID DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_static_site_slug ON static_site (slug)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE static_site');
    }
}

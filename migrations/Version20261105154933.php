<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates static_site, one row per site a build request came in for.
 */
final class Version20261105154933 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create static_site';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE static_site (
              static_site_id UUID NOT NULL,
              collective_id INT DEFAULT NULL,
              slug VARCHAR(128) NOT NULL,
              title VARCHAR(200) NOT NULL,
              PRIMARY KEY (static_site_id)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE static_site');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `prelaunch_signup` — addresses collected by the coming-soon stub's "notify me" form.
 *
 * Unmapped on purpose (see `ComingSoonController`), which is why `doctrine.dbal.schema_filter`
 * excludes it: a table the ORM mapping does not know about is otherwise diffed as unwanted, and
 * every future `make migration.make` would generate a `DROP TABLE prelaunch_signup` for somebody to
 * notice — or not.
 *
 * The email is the primary key because uniqueness is the table's only rule and the controller's
 * `ON CONFLICT (email)` needs a constraint to name. Stored lower-cased by the controller.
 */
final class Version20260926190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create prelaunch_signup for the coming-soon page\'s launch-notification list';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE prelaunch_signup (
                email VARCHAR(254) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (email)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE prelaunch_signup');
    }
}

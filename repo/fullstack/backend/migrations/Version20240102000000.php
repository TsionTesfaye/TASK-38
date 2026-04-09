<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240102000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add hold_attempt_log table for attempt-based throttling';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE hold_attempt_log (
            id CHAR(36) NOT NULL,
            inventory_item_id CHAR(36) NOT NULL,
            attempted_at DATETIME NOT NULL,
            INDEX IDX_hold_attempt_log_item_time (inventory_item_id, attempted_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE hold_attempt_log');
    }
}

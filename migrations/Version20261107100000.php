<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reserved_balance to wallets';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `wallets` ADD COLUMN `reserved_balance` DOUBLE NOT NULL DEFAULT 0 AFTER `balance`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `wallets` DROP COLUMN `reserved_balance`');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Soft delete for wallets: deleted_at, and one ACTIVE wallet per user and currency';
    }

    public function up(Schema $schema): void
    {
        // Rows are never removed: transactions point to wallets with ON DELETE RESTRICT.
        $this->addSql(<<<SQL
            ALTER TABLE `wallets`
                ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL
            SQL);

        // 1 for an active wallet, NULL for a deleted one. A unique index treats NULLs as different,
        // so a user can have many deleted wallets in a currency, but only one active.
        $this->addSql(<<<SQL
            ALTER TABLE `wallets`
                ADD COLUMN `active_flag` TINYINT AS (IF(`deleted_at` IS NULL, 1, NULL)) STORED
            SQL);

        // The new index first: the old one serves the foreign key on user_id and cannot go before.
        $this->addSql(<<<SQL
            ALTER TABLE `wallets`
                ADD UNIQUE KEY `wallet_user_currency_active_unique` (`user_id`, `currency`, `active_flag`)
            SQL);

        $this->addSql('ALTER TABLE `wallets` DROP INDEX `wallet_user_currency_unique`');
    }

    public function down(Schema $schema): void
    {
        // Fails if a user has a deleted and an active wallet in one currency; such data needs
        // a human decision, so the migration does not merge or drop anything by itself.
        $this->addSql(<<<SQL
            ALTER TABLE `wallets`
                ADD UNIQUE KEY `wallet_user_currency_unique` (`user_id`, `currency`)
            SQL);

        $this->addSql('ALTER TABLE `wallets` DROP INDEX `wallet_user_currency_active_unique`');
        $this->addSql('ALTER TABLE `wallets` DROP COLUMN `active_flag`');
        $this->addSql('ALTER TABLE `wallets` DROP COLUMN `deleted_at`');
    }
}

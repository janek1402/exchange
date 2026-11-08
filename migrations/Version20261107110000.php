<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store wallet balances as DECIMAL(15,4), like transaction amounts, instead of DOUBLE';
    }

    public function up(Schema $schema): void
    {
        // A value that does not fit DECIMAL(15,4) stops the migration with an error instead of
        // being cut. Extra decimals (float noise such as 0.30000000000000004) are rounded to 4.
        $this->addSql(<<<SQL
            ALTER TABLE `wallets`
                MODIFY COLUMN `balance`          DECIMAL(15,4) NOT NULL DEFAULT 0,
                MODIFY COLUMN `reserved_balance` DECIMAL(15,4) NOT NULL DEFAULT 0
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE `wallets`
                MODIFY COLUMN `balance`          DOUBLE NOT NULL DEFAULT 0,
                MODIFY COLUMN `reserved_balance` DOUBLE NOT NULL DEFAULT 0
            SQL);
    }
}

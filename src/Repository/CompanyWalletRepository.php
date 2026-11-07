<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CompanyWallet;
use App\Enum\Currency;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

readonly class CompanyWalletRepository implements CompanyWalletRepositoryInterface
{
    private const string TABLE_NAME = 'company_wallets';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @throws Exception
     */
    public function findByCurrency(Currency $currency): ?CompanyWallet
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where('currency = :currency');

        $row = $this->connection->fetchAssociative($qb->getSQL(), ['currency' => $currency->value]);

        if (!$row) {
            return null;
        }

        return $this->buildEntity($row);
    }

    /**
     * @return CompanyWallet[]
     *
     * @throws Exception
     */
    public function findAll(): array
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->select('*')
            ->from(self::TABLE_NAME)
            ->orderBy('currency', 'ASC');

        $rows = $this->connection->fetchAllAssociative($qb->getSQL(), []);

        return array_map($this->buildEntity(...), $rows);
    }

    /**
     * @throws Exception
     */
    public function addToBalance(Currency $currency, string $amount): void
    {
        $now = new DateTimeImmutable()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        // One query, so two simultaneous first spreads in a currency cannot collide.
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (currency, balance, created_at, updated_at)
                VALUES (:currency, :balance, :created_at, :updated_at)
                ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance), updated_at = VALUES(updated_at)',
                self::TABLE_NAME,
            ),
            [
                'currency' => $currency->value,
                'balance' => $amount,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    private function buildEntity(array $row): CompanyWallet
    {
        return new CompanyWallet(
            id: (int) $row['id'],
            currency: Currency::from($row['currency']),
            balance: (float) $row['balance'],
            createdAt: new DateTimeImmutable($row['created_at']),
            updatedAt: new DateTimeImmutable($row['updated_at']),
        );
    }
}

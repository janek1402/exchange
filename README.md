# Exchange

## About

Exchange is a currency exchange platform where clients can hold accounts with **multi-currency wallets** and convert
funds between supported currencies.

The company earns revenue on every exchange through a **spread** — the difference between the market rate and the rate
offered to the client. The spread is calculated dynamically based on the liquidity of the traded currency pair:

- A **base spread of 0.5%** is applied to every exchange.
- Each currency has a **liquidity score** (USD = 1.00 being the most liquid, HUF = 0.40 the least). Less liquid pairs
  receive a higher spread, because they carry more risk and are harder to hedge.
- The formula: `spread = price × (0.5% ÷ average pair liquidity)`

**Example:** exchanging PLN (liquidity 0.55) to HUF (liquidity 0.40) gives an average pair liquidity of 0.475, so the
spread applied is `0.5% ÷ 0.475 ≈ 1.05%` of the transaction value — compared to only `0.5% ÷ 0.975 ≈ 0.51%` for a
USD/EUR pair.

Earnings across all wallets are tracked via the `app:company-wallet` console command.

### Rounding: who gets the fraction of a cent

- Clients only send, deposit and receive **whole units of a currency**: 2 decimal places, `JPY` 0.
- The client receives the converted amount minus the spread, **rounded down** to whole cents (or yen).
  Everything else is the company's spread, rounded **up** to 4 decimal places. The company never gets
  less than the formula above.
- **Example:** 100 PLN → EUR is 23.5910 EUR at market rate. The client receives **23.43 EUR**, the company
  books **0.1611 EUR** (the formula alone gives 0.1573).
- A transfer that would give the client less than 1 cent (1 yen) is refused, and the money stays where it was.

### How a transfer works

1. **Creating a transfer does not move money yet.** The amount is put on hold on the source wallet
   (`reservedBalance`); the balance stays the same. The client can spend only `balance - reservedBalance`.
2. **`app:process-transactions` settles it:** the source wallet is charged, the target wallet credited
   and the spread booked in the company wallet (in the target currency).
3. A transfer is **rejected** (the hold is released, nothing else changes) if a wallet is missing or blocked,
   or the funds are no longer there.
4. **Anti-fraud:** a transfer worth **more than 15,000 EUR** (measured on the amount sent, before the spread,
   whatever the currencies) gets the status `fraud_review` and must be approved by hand. The default answer
   is "no", and the non-interactive mode (`-n`, e.g. cron) skips these transfers instead of deciding.

---

## Working with an AI agent

The owner's guide (in Polish) – how to ask for changes, what a red CI means and when not to accept a
change: [docs/praca-z-agentem-ai.md](docs/praca-z-agentem-ai.md). Rules for the agent itself: [AGENTS.md](AGENTS.md).
Checklist for reviewing changes that touch money (any AI tool; Claude Code uses it via the
`money-reviewer` subagent): [docs/przeglad-zmian-w-pieniadzach.md](docs/przeglad-zmian-w-pieniadzach.md).
What was prepared for working with AI and why, what was deliberately left out, and how it was verified
(in Polish): [AI-DECISIONS.md](AI-DECISIONS.md).

---

## Prerequisites

Make sure you have [Docker](https://www.docker.com/get-started) and [Docker Compose](https://docs.docker.com/compose/)
installed on your machine.

> **Warning:** Check that you don't have any other services running on port **80** before starting the containers.

---

## Getting started

### 1. Start the containers

Run this command from the project root directory:

```bash
docker compose up -d
```

### 2. Install dependencies

Once the containers are up, install PHP dependencies inside the container:

```bash
docker exec -it php-fpm composer install
```

### 3. Run database migrations

Apply the database schema:

```bash
docker exec -it php-fpm php bin/console doctrine:migrations:migrate
```

> **Important:** after pulling new code, run this command again. New code often needs the new migrations
> (e.g. wallet deletion needs the `deleted_at` column) and fails without them.

### 4. Open the application

The application is available at:

```
http://localhost
```

---

## Running commands inside the PHP container

All PHP/Symfony commands must be run inside the `php-fpm` container. The general pattern is:

```bash
docker exec -it php-fpm <command>
```

**Example** — running tests:

```bash
docker exec -it php-fpm composer tests
```

---

## API Endpoints

All endpoints require Bearer token authentication (`401` without a valid token). Obtain a token with `app:create-user`.

| Method   | Path                        | Description                                                                                                                                                                             |
|----------|-----------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `GET`    | `/api/wallets`              | List all wallets belonging to the authenticated user (deleted wallets are not listed).                                                                                                 |
| `POST`   | `/api/wallets`              | Create a new wallet. Body: `{ "currency": "PLN" }`. Supported currencies: `PLN`, `EUR`, `USD`, `GBP`, `JPY`, `CHF`, `HUF`. Returns `409` if an active wallet for that currency already exists. |
| `POST`   | `/api/wallets/{id}/deposit` | Deposit funds into a wallet. Body: `{ "amount": "500.00" }`. Maximum single deposit: `10000`. Returns the wallet.                                                                      |
| `POST`   | `/api/wallets/transfer`     | Transfer funds between two wallets of the authenticated user (currency exchange supported). Body: `{ "fromWalletId": 1, "toWalletId": 2, "amount": "100.00" }`. Returns `201` with the transaction (status `pending` or `fraud_review`). |
| `DELETE` | `/api/wallets/{id}`         | Delete the user's own wallet. Returns `204` with no body. See [Deleting a wallet](#deleting-a-wallet).                                                                                 |

**Wallet** response: `{ "id", "currency", "balance", "reservedBalance", "isBlocked", "lastActivityAt" }`.
Money is returned as text with 4 decimal places, e.g. `"balance": "500.0000"`. `reservedBalance` is money
on hold for transfers in progress.

### Amounts

- Send the amount as **text**: `"amount": "100.50"`. A whole JSON number (`100`) also works, a JSON number
  with a fraction (`100.5`) is refused, because it may already be inexact.
- Plain digits with an optional dot only: `1e3`, `10,50` or `" 10"` are refused.
- At most 2 decimal places (`JPY`: none), and the amount must be greater than 0.

### Errors

Errors come as `{ "error": "message" }`.

| Code  | When                                                                                                                                                                                          |
|-------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `400` | Invalid input: missing field, invalid currency, wallet ID that is not a positive integer, invalid amount (e.g. `Amount must have at most 2 decimal places for PLN.`, `Amount must be a whole number for JPY.`, `Amount cannot exceed 10000.`, `Amount is too small to exchange.`), or a `?confirm=` value that is not true/false. |
| `401` | Missing or invalid token.                                                                                                                                                                     |
| `404` | Wallet not found. **Also for someone else's wallet** (so its existence is not revealed) and for a deleted wallet.                                                                            |
| `409` | Wallet already exists (create), or the wallet cannot be deleted (see below).                                                                                                                 |
| `422` | Transfer within the same wallet, insufficient funds (`balance - reservedBalance`), or a blocked wallet.                                                                                      |

### Deleting a wallet

The wallet disappears from the application, but its row stays in the database, because the transaction
history points to it. Deletion is refused with `409` while:

- money is on hold for transfers in progress (`Wallet 1 has funds reserved by transfers in progress.`),
- any transfer from or to the wallet is still `pending` or `fraud_review` (`Wallet 1 has transfers in progress.`),
- the balance can still be transferred, however small: at least one whole cent (yen) of it gives the client
  at least one cent (yen) in some other currency after the spread, e.g. `0.04 PLN` → `3.35 HUF`
  (`You still have 0.04 PLN that you can transfer. Transfer it before deleting the wallet.`).
  This cannot be overridden.

Only a remainder that **cannot be transferred anywhere** (a part of a cent, or less than about `0.87 HUF`)
can be given up. The first request is refused with `409`
(`Deleting this wallet will lose the remaining 0.5000 HUF. Send the request again with ?confirm=true to delete it.`);
`DELETE /api/wallets/{id}?confirm=true` deletes the wallet and the remainder goes to the company wallet.
A blocked wallet cannot be deleted (`422`). After deletion the user can open a new wallet in the same currency;
it gets a new ID.

A ready-to-use Postman collection is available at [`exchange-api.postman_collection.json`](./exchange-api.postman_collection.json).
Set the `authToken` variable to the token returned by `app:create-user`.

---

## Available console commands

| Command                    | Description                                                                                       |
|----------------------------|---------------------------------------------------------------------------------------------------|
| `app:create-user`          | Creates a user and returns an API token. Use this token when testing endpoints (e.g. in Postman). |
| `app:process-transactions` | Settles `pending` transfers and asks for a decision on each `fraud_review` one (default: reject). With `-n` the fraud review queue is skipped, never approved. Ends with an error code if any transfer could not be processed. |
| `app:company-wallet`       | Displays the company wallets and shows how much the company has earned.                           |

**How to run a console command:**

```bash
docker exec -it php-fpm php bin/console <command-name>
```

**Example:**

```bash
docker exec -it php-fpm php bin/console app:create-user
```

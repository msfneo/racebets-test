# Customer Wallet API

An internal REST API for customer registration, deposits, withdrawals with a
deposit-bonus scheme, and transaction reporting per country and day.

PHP 8.3, MySQL 8.4, PDO with hand-written SQL. No framework, no ORM, no query
builder — per the task specification. Everything runs in Docker.

---

## Running it locally

You need Docker with Compose v2. Nothing else — no PHP or MySQL on the host.

```bash
make up
```

That builds the image, starts MySQL, waits for it to accept connections, applies
the migrations to both the development and the test schema, and starts nginx and
PHP-FPM. When it finishes the API is on **http://localhost:8080**.

```bash
curl localhost:8080/health
```

Without `make`:

```bash
docker compose up -d --build
```

Optional — demo customers and a spread of transactions so the report has
something to show:

```bash
make seed
```

Configuration is read from the environment; the defaults live in
`docker-compose.yml`. Copy `.env.example` to `.env` to override anything (the
API port, credentials, or to point at a MySQL server you already run).

### Other commands

| Command | Description |
| --- | --- |
| `make test` | Whole test suite |
| `make test-unit` | Unit tests only (no database) |
| `make test-concurrency` | Parallel-request tests only |
| `make smoke` | Walks the entire spec with curl against the running API |
| `make logs` | Follow application logs |
| `make mysql` | MySQL prompt on the dev schema |
| `make fresh` | Drop every table and re-migrate |
| `make down` | Stop the stack |
| `make help` | List all targets |

`make smoke` is the quickest way to see everything work at once — it registers a
customer, edits it, demonstrates the bonus on the third deposit, proves bonus
money cannot be withdrawn, fires twelve simultaneous withdrawals at one account,
and prints the report.

---

## Endpoints

Base URL `http://localhost:8080`. JSON in, JSON out. No authentication, as
specified. Amounts are decimal strings in EUR.

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/customers` | Register a customer |
| `GET` | `/customers` | List customers (`?limit=&offset=`) |
| `GET` | `/customers/{id}` | Fetch one customer with balances |
| `PATCH` | `/customers/{id}` | Edit the details given on registration |
| `POST` | `/customers/{id}/deposits` | Deposit money |
| `POST` | `/customers/{id}/withdrawals` | Withdraw money |
| `GET` | `/customers/{id}/transactions` | That customer's ledger |
| `GET` | `/reports/transactions` | Totals per country and date (`?from=&to=`) |
| `GET` | `/health` | Liveness probe |

Successful responses are wrapped in `data`, failures in `error`:

```json
{ "error": { "code": "insufficient_funds", "message": "…", "details": { "amount": ["…"] } } }
```

| Status | When |
| --- | --- |
| `201` | Customer registered, deposit or withdrawal applied |
| `200` | Read, or a successful edit |
| `400` | Body is not valid JSON |
| `404` | No such route, or no such customer |
| `405` | Method not allowed on an existing path |
| `409` | Email address already registered |
| `422` | Validation failed, or insufficient funds |

### Register a customer

A bonus rate between 5% and 20% is drawn at registration and is fixed for the
life of the account — it cannot be set or edited by a client.

```bash
curl -X POST localhost:8080/customers -H 'Content-Type: application/json' -d '{
  "gender": "female",
  "first_name": "Anna",
  "last_name": "Schmidt",
  "country": "DE",
  "email": "anna.schmidt@example.com"
}'
```

`gender` is one of `male`, `female`, `other`. `country` is an ISO 3166-1 alpha-2
code, validated against the real code list and stored upper case. Email is
lower-cased and must be unique. Every validation problem in a payload is
reported at once rather than one per round trip, and unknown fields are rejected
rather than silently ignored — a misspelled `firstname` should not look like a
successful update that did nothing.

```json
{
  "data": {
    "id": 1,
    "gender": "female",
    "first_name": "Anna",
    "last_name": "Schmidt",
    "country": "DE",
    "email": "anna.schmidt@example.com",
    "bonus_percent": 7,
    "balance": {
      "currency": "EUR",
      "real": "0.00",
      "bonus": "0.00",
      "total": "0.00",
      "withdrawable": "0.00"
    },
    "deposit_count": 0,
    "deposits_until_next_bonus": 3,
    "created_at": "2026-03-15T12:00:00+00:00",
    "updated_at": "2026-03-15T12:00:00+00:00"
  }
}
```

### Edit a customer

Any subset of the registration fields:

```bash
curl -X PATCH localhost:8080/customers/1 -H 'Content-Type: application/json' \
  -d '{"last_name": "Schmidt-Weber", "country": "AT"}'
```

### Deposit

```bash
curl -X POST localhost:8080/customers/1/deposits \
  -H 'Content-Type: application/json' -d '{"amount": "100.00"}'
```

Every third deposit is credited with a bonus at the customer's rate. The
response carries the deposit, the bonus row when one was earned, and the
customer's state afterwards:

```json
{
  "data": {
    "transaction": { "id": 3, "type": "deposit", "amount": "100.00", "…": "…" },
    "bonus":       { "id": 4, "type": "bonus", "amount": "10.00", "parent_id": 3, "…": "…" },
    "customer":    { "balance": { "real": "300.00", "bonus": "10.00", "total": "310.00", "withdrawable": "300.00" } }
  }
}
```

### Withdraw

```bash
curl -X POST localhost:8080/customers/1/withdrawals \
  -H 'Content-Type: application/json' -d '{"amount": "50.00"}'
```

Only real money is withdrawable. With 310.00 total of which 10.00 is bonus, the
largest possible withdrawal is 300.00; anything more is `422 insufficient_funds`
and nothing is written. The balance can never go below zero.

### Report

```bash
curl 'localhost:8080/reports/transactions?from=2026-03-09&to=2026-03-15'
```

Both bounds are inclusive dates. Omitted, the window is the last 7 days: today
and the six days before it.

```json
{
  "data": {
    "from": "2026-03-09",
    "to": "2026-03-15",
    "currency": "EUR",
    "timezone": "UTC",
    "rows": [
      {
        "date": "2026-03-15",
        "country": "MT",
        "unique_customers": 32,
        "deposits":    { "count": 45, "amount": "456.34" },
        "withdrawals": { "count": 24, "amount": "-200.45" }
      }
    ]
  }
}
```

Rows are ordered newest day first, then country. `unique_customers` counts
customers with at least one deposit **or** withdrawal that day, matching the
specification.

---

## Design notes

### Money is never a float

Every amount is a signed integer number of euro cents, in PHP (`Money`) and in
the database (`BIGINT`). A float cannot represent `0.10` exactly and accumulates
error as you sum it — unacceptable for balances. Amounts arriving over HTTP are
parsed from their decimal string straight into cents; a value with more than two
decimals is rejected rather than quietly rounded.

Send amounts as JSON **strings** (`"100.00"`). Numbers are accepted and handled
carefully, but a JSON float has already lost precision before it reaches the
application, so a string leaves no room for doubt.

### Concurrency

> *"Financial operations need to be implemented in a way that ensures data
> integrity also for situations where different transaction requests are made at
> the same moment."*

Every balance change runs inside one database transaction that opens by taking
an exclusive lock on the customer row:

```sql
SELECT … FROM customers WHERE id = :id FOR UPDATE
```

Two requests for the same customer therefore serialise: the second only reads
`deposit_count` and `real_balance` once the first has committed. Requests for
*different* customers never contend, because the lock is on one row rather than
the table.

Three further layers back that up, so a mistake anywhere in the chain fails
loudly instead of corrupting a balance:

1. Balances are written as **relative** updates (`real_balance = real_balance + :x`),
   never as an absolute value computed in PHP, so a lost update is impossible.
2. The withdrawal `UPDATE` carries `AND real_balance >= :minimum` and checks how
   many rows it touched.
3. `CHECK (real_balance >= 0)` and `CHECK (bonus_balance >= 0)` in the schema are
   the final backstop at the storage layer.

`TransactionManager` retries a unit of work up to three times if InnoDB picks it
as a deadlock victim. That is safe because a retry only ever happens after a full
rollback — no partial work survives.

**This is tested, not asserted.** `tests/Integration/ConcurrencyTest.php` launches
real parallel OS processes, each with its own database connection, released
together by a shared start timestamp:

- ten simultaneous withdrawals of 20.00 against a balance of 100.00 — exactly
  five must succeed and the balance must land on exactly zero;
- nine simultaneous deposits — exactly three bonuses, on the 3rd, 6th and 9th;
- mixed deposit/withdrawal traffic — the stored balance must still equal the sum
  of the ledger.

I verified these tests actually catch the bug rather than passing by luck: with
the `FOR UPDATE` removed, the nine concurrent deposits award **zero** bonuses
instead of three, and the suite fails. `make smoke` demonstrates the same thing
over HTTP through nginx — twelve parallel withdrawal requests, five `201`s,
seven `422`s, final balance `0.00`.

### The ledger

`transactions` is append-only; rows are never updated or deleted. The balances on
`customers` are a running total that must always equal the sum of the ledger, and
an integration test asserts exactly that after a mixed workload.

- **Withdrawals are stored negative.** Sums are then additive, and the report's
  withdrawal totals come out negative as in the specification's example
  (`-200.45`).
- **A bonus is its own row**, of type `bonus`, pointing at the deposit that
  triggered it. Folding it into the deposit amount would overstate deposits in
  the report; keeping it separate lets the report count real deposits without
  subtracting bonuses back out. Bonus rows are excluded from the report totals.
- **Country is snapshot onto each transaction** rather than joined from
  `customers`. A report is a historical record: if a customer moves from MT to
  DE today, last week's figures must not silently move with them. There is a
  test for this.
- Each row also stores the balances it produced, so the ledger can be audited
  without replaying it from the beginning.

### Time

Everything is UTC — the PHP process, the MySQL session, and the MySQL server
(`--default-time-zone=+00:00`). The report groups by UTC date. `Clock` is
injected rather than calling `now()` inline, which is what lets the report tests
pin exact dates instead of depending on when the suite happens to run.

### Structure

```
public/index.php          Front controller
src/
  Http/                   Router, request/response, kernel, controllers
  Application/            Use cases: customer, transaction, report services
  Domain/                 Money, Customer, Transaction, BonusPolicy, exceptions
  Infrastructure/         PDO connection, transaction manager, migrator, repositories
  Support/                Env, Clock
migrations/               Plain .sql, applied in filename order
tests/Unit/               Domain logic, no database
tests/Integration/        Real MySQL, dispatched through the HTTP kernel
bin/console               migrate | migrate:fresh | seed
bin/smoke-test.sh         End-to-end curl walkthrough
```

Controllers do no work beyond translating HTTP to a use case. Services own the
transaction boundary. Repositories own the SQL. Domain types hold the rules —
the bonus interval and rate range live in `BonusPolicy` and nowhere else.

Integration tests dispatch straight through the kernel rather than over a socket,
so the whole stack from routing to SQL is exercised without needing the web
server running for the suite.

There is no DI container library, no framework. At this size a dozen typed
factory methods in `Container` are easier to follow and impossible to
misconfigure; tests construct the same container with a frozen clock.

### Tests

86 tests, 2239 assertions.

```bash
make test
```

---

## Things I would add next

Deliberately left out to keep the scope to the specification, but they are what
I would reach for next in a real system:

- **Idempotency keys** on deposits and withdrawals. Row locking makes concurrent
  requests safe, but it cannot tell a genuine second deposit from a client that
  retried after a timeout. An `Idempotency-Key` header with a unique index would
  close that gap — the one thing I most miss here.
- **Multi-currency.** Everything is EUR and the column is named as such; a real
  wallet needs a currency per balance and explicit conversion rules.
- **Bonus expiry and wagering requirements**, which is how a bonus balance
  normally converts into withdrawable money.
- **Authentication and rate limiting** — not needed for an internal service, per
  the brief, but the boundary is the obvious place for it.
- **A reporting read-model** if the ledger grows large. The current query is
  indexed on `(occurred_on, country, type)` and is fine for a long time, but
  daily aggregates eventually beat scanning raw rows.
- **Static analysis in CI** (PHPStan at max, PHP-CS-Fixer). The code is written
  to pass both; I did not add the tooling itself to keep the dependency list to
  PHPUnit alone.

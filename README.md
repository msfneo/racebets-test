# Customer Wallet API

An internal REST API for customer registration, deposits, withdrawals with a
deposit bonus, and transaction reporting per country and day.

PHP 8.3, MySQL 8.4, PDO with hand-written SQL. The brief forbids an ORM or query
builder, so the project uses neither, and it pulls in no framework. Docker runs
the whole stack.

---

## Running it locally

You need Docker with Compose v2. No PHP or MySQL on the host.

```bash
make up
```

That command builds the image, starts MySQL, waits for it to accept connections,
migrates both the development and the test schema, then starts nginx and PHP-FPM.
The API comes up on **http://localhost:8080**.

```bash
curl localhost:8080/health
```

Without `make`:

```bash
docker compose up -d --build
```

For demo customers and a spread of transactions, so the report has something to
show:

```bash
make seed
```

The app reads its configuration from the environment, and the defaults live in
`docker-compose.yml`. Copy `.env.example` to `.env` to change the API port, the
credentials, or the database host.

### Other commands

| Command | Description |
| --- | --- |
| `make test` | Whole test suite |
| `make test-unit` | Unit tests only (no database) |
| `make test-concurrency` | Parallel-request tests only |
| `make smoke` | Walks the entire brief with curl against the running API |
| `make logs` | Follow application logs |
| `make mysql` | MySQL prompt on the dev schema |
| `make fresh` | Drop every table and re-migrate |
| `make down` | Stop the stack |
| `make help` | List all targets |

`make smoke` walks the whole brief in one pass. It registers a customer, edits
it, shows the bonus landing on the third deposit, refuses a withdrawal that dips
into bonus money, fires twelve simultaneous withdrawals at one account, then
prints the report.

---

## Endpoints

Base URL `http://localhost:8080`. JSON in, JSON out, no authentication. Amounts
are decimal strings in EUR.

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

The API wraps a success in `data` and a failure in `error`:

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

The API draws a bonus rate between 5% and 20% at registration and fixes it for
the life of the account. No client can set it or change it later.

```bash
curl -X POST localhost:8080/customers -H 'Content-Type: application/json' -d '{
  "gender": "female",
  "first_name": "Anna",
  "last_name": "Schmidt",
  "country": "DE",
  "email": "anna.schmidt@example.com"
}'
```

`gender` takes `male`, `female` or `other`. `country` takes an ISO 3166-1 alpha-2
code, which the API checks against the real code list and stores upper case. It
lower-cases the email and requires it to be unique.

You get back every validation problem in one response, so fixing a payload takes
one round trip. Unknown fields fail the request, which stops a misspelled
`firstname` from looking like an update that succeeded and changed nothing.

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

Send any subset of the registration fields:

```bash
curl -X PATCH localhost:8080/customers/1 -H 'Content-Type: application/json' \
  -d '{"last_name": "Schmidt-Weber", "country": "AT"}'
```

### Deposit

```bash
curl -X POST localhost:8080/customers/1/deposits \
  -H 'Content-Type: application/json' -d '{"amount": "100.00"}'
```

Every third deposit earns a bonus at the customer's rate. The response carries
the deposit, the bonus row when the customer earned one, and their state
afterwards:

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

You can withdraw real money only. With 310.00 total of which 10.00 is bonus, the
ceiling sits at 300.00. Ask for more and the API answers `422 insufficient_funds`
and writes nothing. A `CHECK` constraint holds the balance at or above zero.

### Report

```bash
curl 'localhost:8080/reports/transactions?from=2026-03-09&to=2026-03-15'
```

Both bounds are inclusive dates. Leave them off and you get the last 7 days:
today and the six before it.

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

Rows come back newest day first, then country ascending. `unique_customers`
counts customers with at least one deposit **or** withdrawal that day, which
matches the wording in the brief.

---

## Design notes

### Money is never a float

Every amount is a signed integer count of euro cents, in PHP (`Money`) and in the
database (`BIGINT`). A float cannot hold `0.10`, and the error compounds as you
sum it, which rules floats out for balances.

`Money` parses an incoming decimal string straight into cents and rejects a third
decimal place. Nothing rounds on the way in.

Send amounts as JSON **strings** (`"100.00"`). The API takes numbers too, but a
JSON float has already lost precision before it arrives, so a string leaves
nothing to interpret.

### Concurrency

> *"Financial operations need to be implemented in a way that ensures data
> integrity also for situations where different transaction requests are made at
> the same moment."*

Every balance change runs inside one database transaction that opens by taking an
exclusive lock on the customer row:

```sql
SELECT … FROM customers WHERE id = :id FOR UPDATE
```

Two requests for the same customer serialise: the second reads `deposit_count`
and `real_balance` only after the first commits. Requests for different customers
never contend, since the lock covers one row and not the table.

Three further layers stand behind it, so a mistake in the chain fails loudly
instead of corrupting a balance:

1. The code writes balances as **relative** updates
   (`real_balance = real_balance + :x`) and never as a value it computed in PHP,
   which makes a lost update arithmetically impossible.
2. The withdrawal `UPDATE` carries `AND real_balance >= :minimum` and checks how
   many rows it touched.
3. `CHECK (real_balance >= 0)` and `CHECK (bonus_balance >= 0)` in the schema
   catch anything that reaches the storage layer.

`TransactionManager` retries a unit of work up to three times when InnoDB picks
it as a deadlock victim. A retry follows a full rollback, so no partial work
survives into the next attempt.

The tests prove it. `tests/Integration/ConcurrencyTest.php` launches parallel OS
processes, each holding its own database connection, released together by a
shared start timestamp:

- ten simultaneous withdrawals of 20.00 against a balance of 100.00, where five
  must succeed and the balance must land on zero;
- nine simultaneous deposits, which must produce three bonuses, on the 3rd, 6th
  and 9th;
- mixed deposit and withdrawal traffic, after which the stored balance must still
  equal the sum of the ledger.

To confirm the tests catch the bug instead of passing by luck, I removed the
`FOR UPDATE` and ran them again: the nine concurrent deposits awarded **zero**
bonuses instead of three, and the suite failed. `make smoke` shows the same
guarantee over HTTP through nginx, firing twelve parallel withdrawal requests for
five `201`s, seven `422`s and a final balance of `0.00`.

### The ledger

`transactions` is append-only. Nothing updates or deletes a row once written. The
balances on `customers` are a running total that must equal the sum of the
ledger, and an integration test checks that after a mixed workload.

- **Withdrawals sit in the table as negative amounts.** Sums stay additive, and
  the report's withdrawal totals come out negative, as in the example table in
  the brief (`-200.45`).
- **A bonus gets its own row**, typed `bonus`, pointing at the deposit that
  triggered it. Folding it into the deposit amount would overstate deposits in
  the report. Keeping it separate lets the report count real deposits without
  subtracting bonuses back out, and the report query skips bonus rows entirely.
- **Each transaction stores its own country** instead of joining `customers` for
  it. A report records what was true at the time: if a customer moves from MT to
  DE today, last week's Malta figures stay where they are. A test covers this.
- Each row also carries the balances it produced, so you can audit the ledger
  without replaying it from the beginning.

### Time

The PHP process, the MySQL session and the MySQL server all run in UTC
(`--default-time-zone=+00:00`), and the report groups by UTC date. Services take
a `Clock` instead of calling `now()` inline, so the report tests pin exact dates
and do not depend on when you run the suite.

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

Controllers translate HTTP into a use case and stop there. Services own the
transaction boundary, repositories own the SQL, and the domain types hold the
rules: the bonus interval and rate range live in `BonusPolicy` and nowhere else.

Integration tests dispatch straight through the kernel instead of over a socket,
so they exercise routing down to SQL without a web server running.

The project carries no DI container library and no framework. At this size a
dozen typed factory methods in `Container` are easier to follow and harder to
misconfigure, and the tests build that same container with a frozen clock.

### Tests

87 tests, 2241 assertions.

```bash
make test
```

---

## Things I would add next

I left these out to keep the scope on the brief. In a real system they come next.

- **Idempotency keys** on deposits and withdrawals. Row locking makes concurrent
  requests safe, but it cannot tell a second deposit from a client retrying after
  a timeout. An `Idempotency-Key` header with a unique index on
  `(customer_id, key)` closes that gap, and of everything on this list it is the
  one I miss most.
- **Multi-currency.** The whole system assumes EUR and the columns say so. A real
  wallet needs a currency per balance and explicit conversion rules.
- **Bonus expiry and wagering requirements**, which is how a bonus balance
  converts into withdrawable money in a betting product.
- **Authentication and rate limiting.** An internal service does not need them,
  per the brief, but the HTTP boundary is where they would go.
- **A reporting read-model** once the ledger grows. The query runs on an index
  over `(occurred_on, country, type)` and will hold up for a long time, though
  daily aggregate tables beat scanning raw rows in the end.
- **Static analysis in CI** (PHPStan at max, PHP-CS-Fixer). I wrote the code to
  pass both and skipped the tooling itself, which keeps the dependency list at
  PHPUnit alone.

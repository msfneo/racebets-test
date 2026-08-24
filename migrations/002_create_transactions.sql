-- Append-only ledger. Rows are never updated or deleted; the balances on
-- `customers` are a running total that must always equal the sum of the ledger.
CREATE TABLE transactions (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id         BIGINT UNSIGNED NOT NULL,

    -- A 'bonus' row points at the deposit that triggered it.
    parent_id           BIGINT UNSIGNED NULL,

    type                ENUM('deposit', 'withdrawal', 'bonus') NOT NULL,

    -- Signed minor units: deposits and bonuses are positive, withdrawals are
    -- negative. That makes the reporting sums additive and matches the sign
    -- convention of the report in the specification (-200.45).
    amount              BIGINT NOT NULL,

    -- Balances after applying this row, so the ledger can be audited without
    -- replaying it from the beginning.
    real_balance_after  BIGINT NOT NULL,
    bonus_balance_after BIGINT NOT NULL,

    -- Country snapshot. The report must not change retroactively when a
    -- customer later edits their country, so it is copied in at write time
    -- instead of being joined from `customers`.
    country             CHAR(2) NOT NULL,

    occurred_at         DATETIME(6) NOT NULL,
    -- Materialised grouping key for the per-day report.
    occurred_on         DATE AS (DATE(occurred_at)) STORED NOT NULL,

    PRIMARY KEY (id),

    KEY idx_transactions_customer (customer_id, id),
    KEY idx_transactions_report (occurred_on, country, type),

    CONSTRAINT fk_transactions_customer
        FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_transactions_parent
        FOREIGN KEY (parent_id) REFERENCES transactions (id),

    CONSTRAINT chk_transactions_sign CHECK (
        (type = 'deposit'    AND amount > 0) OR
        (type = 'bonus'      AND amount > 0) OR
        (type = 'withdrawal' AND amount < 0)
    )
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_0900_ai_ci;

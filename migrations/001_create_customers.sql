CREATE TABLE customers (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    gender        ENUM('male', 'female', 'other') NOT NULL,
    first_name    VARCHAR(100)    NOT NULL,
    last_name     VARCHAR(100)    NOT NULL,
    -- ISO 3166-1 alpha-2, always stored upper case.
    country       CHAR(2)         NOT NULL,
    email         VARCHAR(190)    NOT NULL,

    -- Assigned once at registration, 5..20 inclusive. Not editable afterwards.
    bonus_percent TINYINT UNSIGNED NOT NULL,

    -- All money is stored as signed integer minor units (euro cents). Floats are
    -- never used for balances, in the database or in PHP.
    real_balance  BIGINT NOT NULL DEFAULT 0,
    bonus_balance BIGINT NOT NULL DEFAULT 0,

    -- Drives the "every 3rd deposit" bonus rule. Incremented in the same
    -- transaction (and under the same row lock) as the balance update.
    deposit_count INT UNSIGNED NOT NULL DEFAULT 0,

    created_at    DATETIME(6) NOT NULL,
    updated_at    DATETIME(6) NOT NULL,

    PRIMARY KEY (id),

    -- The collation is accent- and case-insensitive, so this also rejects
    -- "JOHN@Example.com" once "john@example.com" exists. The application
    -- lower-cases addresses on top of that.
    UNIQUE KEY uniq_customers_email (email),

    -- Last line of defence: even a bug in the service layer cannot drive a
    -- balance negative or hand out an out-of-range bonus.
    CONSTRAINT chk_customers_bonus_percent CHECK (bonus_percent BETWEEN 5 AND 20),
    CONSTRAINT chk_customers_real_balance  CHECK (real_balance >= 0),
    CONSTRAINT chk_customers_bonus_balance CHECK (bonus_balance >= 0)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_0900_ai_ci;

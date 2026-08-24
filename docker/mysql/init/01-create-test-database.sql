-- The integration suite runs against a dedicated schema so that a test run never
-- touches the data you created by hand while poking at the API.
CREATE DATABASE IF NOT EXISTS `wallet_test`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

GRANT ALL PRIVILEGES ON `wallet_test`.* TO 'wallet'@'%';
FLUSH PRIVILEGES;

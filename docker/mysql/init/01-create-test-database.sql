-- Runs once, on the first boot of a fresh mysql volume.
--
-- The Pest suite uses a separate database from development (see
-- tests/TestCase.php for why: RefreshDatabase drops every table it
-- touches, and it used to be pointed at the development one). The mysql
-- image only creates and grants MYSQL_DATABASE, so the test database has
-- to be created — and granted to the app user — here.
CREATE DATABASE IF NOT EXISTS waqar_testing
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON waqar_testing.* TO 'waqar'@'%';
FLUSH PRIVILEGES;

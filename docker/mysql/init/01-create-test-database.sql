-- Runs once, when MySQL initialises an empty data directory.
-- The test suite (phpunit.xml) points at this database so feature tests exercise
-- the same engine as production instead of SQLite.
CREATE DATABASE IF NOT EXISTS `wtg_test`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `wtg_test`.* TO 'wtg'@'%';
FLUSH PRIVILEGES;

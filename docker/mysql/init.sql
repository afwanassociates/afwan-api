-- Runs once, when the mysql_data volume is first created.
-- Separate database for the test suite (RefreshDatabase wipes it).
CREATE DATABASE IF NOT EXISTS `afwan_api_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `afwan_api_test`.* TO 'afwan'@'%';
FLUSH PRIVILEGES;

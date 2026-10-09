CREATE DATABASE IF NOT EXISTS systemkeeper_testing;
GRANT ALL PRIVILEGES ON systemkeeper_testing.* TO 'systemkeeper'@'%';
-- временные базы тестового восстановления дампов (sk_restore_<id>)
GRANT ALL PRIVILEGES ON `sk\_restore\_%`.* TO 'systemkeeper'@'%';

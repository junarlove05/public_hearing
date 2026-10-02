-- ====================================================================
-- Migration 014: Admin 3-Week Password Rotation Tracking in LPH
-- ====================================================================

-- Add password_changed_at column if it does not already exist
SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'password_changed_at'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `password_changed_at` DATETIME NULL DEFAULT NULL AFTER `last_login_at`',
    'SELECT "Column password_changed_at already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill existing accounts with their creation timestamp so baseline is established
UPDATE `users`
SET `password_changed_at` = COALESCE(`created_at`, NOW())
WHERE `password_changed_at` IS NULL;

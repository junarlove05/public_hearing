<?php
/**
 * config/database.php
 * ------------------------------------------------------------------
 * PDO database connection (singleton). Include this file to obtain
 * a ready-to-use PDO instance via Database::getInstance().
 *
 * DOES NOT create, alter, or drop any table. It only connects to the
 * existing `legislative_public_hearing_db` database.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';

class Database
{
    private static ?PDO $instance = null;

    // Prevent direct instantiation
    private function __construct() {}

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                error_log('Database connection failed: ' . $e->getMessage());
                if (APP_DEBUG) {
                    die('Database connection failed: ' . $e->getMessage());
                }
                die('A system error occurred. Please try again later.');
            }
        }

        return self::$instance;
    }

    // Disallow cloning of the instance
    private function __clone() {}
}

/**
 * Convenience helper so pages can simply call db() instead of
 * Database::getInstance().
 */
function db(): PDO
{
    return Database::getInstance();
}

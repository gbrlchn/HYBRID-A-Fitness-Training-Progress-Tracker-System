<?php
/**
 * db_connect.php - PDO connection. Edit the constants for your environment.
 * Exposes $pdo (exceptions on error, real prepared statements).
 */
const DB_HOST = 'localhost';
const DB_NAME = 'hybrid_fitness';
const DB_USER = 'root';
const DB_PASS = '';          // XAMPP default; set a real password in production

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log($e->getMessage());               // log details, never show them
    http_response_code(500);
    exit('Database connection failed.');
}

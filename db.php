<?php
// ── Database connection ───────────────────────────────────────
// This file is generated/overwritten by install.sh
// Do NOT edit manually — change credentials in install.sh

define('DB_HOST', 'localhost');
define('DB_NAME', 'budsjett');
define('DB_USER', 'budsjett_user');
define('DB_PASS', 'PLACEHOLDER');   // replaced by install.sh

function getDB(): PDO {
    static $pdo = null;
    if (!$pdo) {
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
    }
    return $pdo;
}

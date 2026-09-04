<?php

declare(strict_types=1);

/**
 * Datenbank-Verbindung für MySQL/MariaDB via mysqli.
 * Zugangsdaten aus Umgebungsvariablen oder .env.
 */
return [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'name' => getenv('DB_NAME') ?: 'krisp',
    'user' => getenv('DB_USER') ?: 'krisp',
    'pass' => getenv('DB_PASS') ?: '',
    'charset' => 'utf8mb4',
];

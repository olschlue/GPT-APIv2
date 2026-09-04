<?php

declare(strict_types=1);

namespace App\Database;

use mysqli;
use RuntimeException;

/**
 * Datenbank-Verbindung (Singleton-ähnlich).
 */
final class Database
{
    private static ?mysqli $connection = null;

    /**
     * Liefert die aktive mysqli-Verbindung.
     *
     * @throws RuntimeException bei Verbindungsfehler
     */
    public static function getConnection(): mysqli
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $config = require APP_ROOT . '/config/database.php';

        $mysqli = new mysqli(
            $config['host'],
            $config['user'],
            $config['pass'],
            $config['name']
        );

        if ($mysqli->connect_errno) {
            throw new RuntimeException(
                'Datenbankverbindung fehlgeschlagen: ' . $mysqli->connect_error
            );
        }

        $mysqli->set_charset($config['charset']);
        self::$connection = $mysqli;

        return $mysqli;
    }

    /**
     * Prüft, ob die Datenbank erreichbar ist.
     */
    public static function isAvailable(): bool
    {
        try {
            self::getConnection();
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Schließt die Verbindung (für Tests).
     */
    public static function close(): void
    {
        if (self::$connection !== null) {
            self::$connection->close();
            self::$connection = null;
        }
    }
}

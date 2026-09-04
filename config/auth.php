<?php

declare(strict_types=1);

/**
 * API-Key für Authentifizierung.
 * Muss in .env als API_KEY gesetzt werden.
 */
return [
    'api_key' => getenv('API_KEY') ?: '',
];

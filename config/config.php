<?php

declare(strict_types=1);

/**
 * Standardwerte der Anwendung.
 * Können per .env-Datei oder echten Umgebungsvariablen überschrieben werden.
 * Priorität: Umgebungsvariable > .env > dieser Default.
 */
return [
    // Geheimer OpenAI API Key – ausschließlich per .env / Umgebungsvariable setzen!
    'OPENAI_API_KEY' => '',

    // Modelle
    'OPENAI_TRANSCRIBE_MODEL' => 'gpt-4o-transcribe',
    'OPENAI_ANALYSIS_MODEL' => 'gpt-5-mini',

    // OpenAI API
    'OPENAI_BASE_URL' => 'https://api.openai.com/v1',
    'HTTP_TIMEOUT_TRANSCRIBE' => 600, // Sekunden (große Audiodateien)
    'HTTP_TIMEOUT_ANALYSIS' => 300,

    // Uploads
    'MAX_UPLOAD_MB' => 200,
    'UPLOAD_DIR' => APP_ROOT . '/storage/uploads',
];

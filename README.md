# GPT-APIv2

PHP-API, die Audio-Dateien (MP3, M4A, WAV, WEBM) per Upload entgegennimmt, mit der OpenAI API transkribiert und anschließend eine strukturierte Auswertung als JSON zurückgibt.

## Endpunkte

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET | `/` | Web-UI (Upload-Formular + Ergebnisansicht) |
| GET | `/api/health` | Health-Check inkl. Konfigurationsstatus |
| POST | `/api/transcribe` | Multipart-Formdata (Feld `file`) → Transkription + Analyse als JSON |

### Beispiel: Transkription

```bash
curl -X POST http://127.0.0.1:8080/api/transcribe \
  -F "file=@meeting.mp3"
```

Antwort (exakt diese Felder):

```json
{
    "transcript": "vollständiger Transkriptionstext …",
    "summary": "Prägnante Zusammenfassung …",
    "outline": [
        { "topic": "Budget", "points": ["Kosten steigen", "Freigabe Q4"] }
    ],
    "tasks": [
        { "task": "Report erstellen", "owner": "Anna", "deadline": "2026-09-10", "priority": "high" }
    ],
    "decisions": ["Launch im Oktober"]
}
```

Fehlerfälle liefern JSON mit `error.code` / `error.message` und passendem HTTP-Status: `400 file_missing`, `413 file_too_large`, `415 unsupported_media_type`, `500 config_missing`, `502 openai_error` u. a.

## Setup

```bash
cp .env.example .env   # OPENAI_API_KEY eintragen
composer install       # optional – ohne Composer greift ein Fallback-Autoloader
```

Konfiguration (Priorität: Umgebungsvariable > `.env` > `config/config.php`):

| Variable | Default | Beschreibung |
|---|---|---|
| `OPENAI_API_KEY` | – | Geheimer API Key (Pflicht für `/api/transcribe`) |
| `OPENAI_TRANSCRIBE_MODEL` | `gpt-4o-transcribe` | Modell für `/audio/transcriptions` |
| `OPENAI_ANALYSIS_MODEL` | `gpt-5-mini` | Modell für die JSON-Auswertung |
| `MAX_UPLOAD_MB` | `200` | Maximale Upload-Größe in MB |
| `OPENAI_BASE_URL` | `https://api.openai.com/v1` | Optional überschreibbar (Proxy etc.) |

## Starten

```bash
composer serve
# oder ohne Composer:
php -d upload_max_filesize=220M -d post_max_size=220M -S 127.0.0.1:8080 -t public
```

Die `php -d`-Flags müssen größer als `MAX_UPLOAD_MB` sein, sonst blockiert PHP den Upload vor der eigenen Validierung.

## Tests

```bash
composer test    # oder: php tests/run.php
```

Eigenständiger Test-Runner ohne externe Abhängigkeiten (Upload-Regeln, Config, Analyse-Schema, Router).

## Struktur

```
├── bootstrap.php          # Autoloading (Composer oder Fallback)
├── config/config.php      # Default-Konfiguration
├── public/                # Document Root (Front Controller)
│   └── index.php
├── src/
│   ├── Analysis/          # Prompt + Schema-Normalisierung der Auswertung
│   ├── Controller/        # WebController (UI), HealthController, TranscribeController
│   ├── Http/              # Request, Response, ApiException
│   ├── OpenAI/            # cURL-Client (Transcriptions + Chat Completions)
│   ├── Upload/            # Validierung + temporäre Speicherung
│   ├── View/              # upload.php – HTML/JS-Oberfläche für die API
│   ├── Config.php
│   └── Router.php
├── storage/uploads/       # temporäre Uploads (werden nach Verarbeitung gelöscht)
└── tests/run.php
```

<?php

declare(strict_types=1);

/**
 * Eigenständiger Test-Runner ohne externe Abhängigkeiten.
 * Aufruf: php tests/run.php  (oder: composer test)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Analysis\AnalysisResult;
use App\Audio\AudioChunker;
use App\Auth\ApiKeyAuth;
use App\Config;
use App\Database\Database;
use App\Database\MeetingRepository;
use App\Http\ApiException;
use App\Router;
use App\Upload\AudioUploadHandler;
use App\Upload\UploadRules;

$failures = 0;

function check(string $name, callable $fn): void
{
    global $failures;
    try {
        $fn();
        echo "✔ {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "✘ {$name}\n    → {$e->getMessage()}\n";
    }
}

function assertTrue(bool $condition, string $message = 'Assertion fehlgeschlagen'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message
            : 'Erwartet: ' . var_export($expected, true) . ' | Erhalten: ' . var_export($actual, true));
    }
}

function expectApiException(string $code, int $status, callable $fn): void
{
    try {
        $fn();
    } catch (ApiException $e) {
        assertSame($code, $e->errorCode(), 'Fehlercode');
        assertSame($status, $e->httpStatus(), 'HTTP-Status');
        return;
    }
    throw new RuntimeException("ApiException {$code} wurde nicht geworfen.");
}

// ---------------------------------------------------------------- UploadRules

check('UploadRules: erlaubte Erweiterungen (mp3, m4a, wav, webm)', function (): void {
    foreach (['a.mp3', 'a.m4a', 'a.wav', 'a.webm', 'A.MP3'] as $name) {
        assertTrue(UploadRules::isAllowedExtension($name), "{$name} sollte erlaubt sein");
    }
});

check('UploadRules: unerlaubte Erweiterungen werden abgelehnt', function (): void {
    foreach (['a.txt', 'a.exe', 'a', 'archive.tar.gz'] as $name) {
        assertTrue(!UploadRules::isAllowedExtension($name), "{$name} sollte abgelehnt werden");
    }
});

check('UploadRules: Größenlimit MAX_UPLOAD_MB', function (): void {
    assertSame(200 * 1024 * 1024, UploadRules::maxBytes(200));
    assertTrue(UploadRules::isSizeAllowed(1, 200));
    assertTrue(UploadRules::isSizeAllowed(UploadRules::maxBytes(200), 200));
    assertTrue(!UploadRules::isSizeAllowed(UploadRules::maxBytes(200) + 1, 200));
    assertTrue(!UploadRules::isSizeAllowed(0, 200));
});

check('UploadRules: MIME-Typen', function (): void {
    assertSame('audio/mpeg', UploadRules::mimeFor('x.mp3'));
    assertSame('audio/webm', UploadRules::mimeFor('x.webm'));
});

// ---------------------------------------------------------------- Config

check('Config: Defaults aus config/config.php', function (): void {
    $config = Config::load(APP_ROOT); // ohne .env greifen die Defaults
    assertSame('gpt-4o-transcribe', $config->getString('OPENAI_TRANSCRIBE_MODEL'));
    assertSame('gpt-5-mini', $config->getString('OPENAI_ANALYSIS_MODEL'));
    assertSame(200, $config->getInt('MAX_UPLOAD_MB'));
});

check('Config: .env überschreibt Defaults', function (): void {
    $root = sys_get_temp_dir() . '/gpt-apiv2-test-' . bin2hex(random_bytes(4));
    mkdir($root . '/config', 0777, true);
    file_put_contents($root . '/config/config.php', "<?php return ['FOO' => 'default', 'MAX_UPLOAD_MB' => 200];");
    file_put_contents($root . '/.env', "FOO=from-env\n# Kommentar\nBAR=\"quoted\"\n");

    $config = Config::load($root);
    assertSame('from-env', $config->getString('FOO'));
    assertSame('quoted', $config->getString('BAR'));
    assertSame(200, $config->getInt('MAX_UPLOAD_MB'));

    unlink($root . '/.env');
    unlink($root . '/config/config.php');
    rmdir($root . '/config');
    rmdir($root);
});

// ---------------------------------------------------------------- AnalysisResult

check('AnalysisResult: vollständige Antwort wird übernommen', function (): void {
    $result = AnalysisResult::fromJson(json_encode([
        'summary' => 'Kurzfassung',
        'outline' => [
            ['id' => '01', 'title' => '**Budget**', 'description' => ''],
            ['id' => '02', 'description' => 'Kosten steigen'],
        ],
        'tasks' => [
            [
                'id' => 'task01',
                'title' => 'Oliver, Report erstellen',
                'assignee' => null,
                'due_date' => '2026-09-10T00:00:00.000Z',
                'completed' => false,
            ],
        ],
        'decisions' => [
            ['id' => 'dec01', 'description' => 'Launch im Oktober'],
            ['id' => 'dec02', 'description' => 'Budget erhöht'],
        ],
        'extra' => 'wird verworfen',
    ]));
    assertSame('Kurzfassung', $result->summary);
    assertSame('01', $result->outline[0]['id']);
    assertSame('**Budget**', $result->outline[0]['title']);
    assertSame('', $result->outline[0]['description']);
    assertSame('02', $result->outline[1]['id']);
    assertSame('Kosten steigen', $result->outline[1]['description']);
    assertTrue(!isset($result->outline[1]['title']), 'Zweiter Eintrag sollte kein title haben');
    assertSame('task01', $result->tasks[0]['id']);
    assertSame('Oliver, Report erstellen', $result->tasks[0]['title']);
    assertSame(null, $result->tasks[0]['assignee']);
    assertSame('2026-09-10T00:00:00.000Z', $result->tasks[0]['due_date']);
    assertSame(false, $result->tasks[0]['completed']);
    assertSame('dec01', $result->decisions[0]['id']);
    assertSame('Launch im Oktober', $result->decisions[0]['description']);
    assertSame('dec02', $result->decisions[1]['id']);
    assertSame('Budget erhöht', $result->decisions[1]['description']);
});

check('AnalysisResult: fehlende Felder → Defaults', function (): void {
    $result = AnalysisResult::fromArray([]);
    assertSame('', $result->summary);
    assertSame([], $result->outline);
    assertSame([], $result->tasks);
    assertSame([], $result->decisions);
});

check('AnalysisResult: decisions als Strings werden zu Objekten', function (): void {
    $result = AnalysisResult::fromArray([
        'decisions' => ['Entscheidung 1', 'Entscheidung 2'],
    ]);
    assertSame(2, count($result->decisions));
    assertTrue(isset($result->decisions[0]['id']), 'ID sollte generiert werden');
    assertSame('Entscheidung 1', $result->decisions[0]['description']);
    assertTrue(isset($result->decisions[1]['id']));
    assertSame('Entscheidung 2', $result->decisions[1]['description']);
});

check('AnalysisResult: task ohne title wird übersprungen', function (): void {
    $result = AnalysisResult::fromArray([
        'tasks' => [
            ['id' => 'x'], // kein title
            ['id' => 'task02', 'title' => 'Gültig', 'assignee' => null, 'due_date' => null, 'completed' => false],
        ],
    ]);
    assertSame(1, count($result->tasks));
    assertSame('Gültig', $result->tasks[0]['title']);
    assertSame(false, $result->tasks[0]['completed']);
});

check('AnalysisResult: Code-Fences werden entfernt', function (): void {
    $result = AnalysisResult::fromJson("```json\n{\"summary\": \"Test\"}\n```");
    assertSame('Test', $result->summary);
});

check('AnalysisResult: ungültiges JSON → 502', function (): void {
    expectApiException('analysis_invalid_json', 502, function (): void {
        AnalysisResult::fromJson('das ist kein JSON');
    });
});

check('AnalysisResult: Einträge ohne title/description werden übersprungen', function (): void {
    $result = AnalysisResult::fromArray([
        'tasks' => [
            ['id' => 'x'], // kein title
            ['id' => 'task02', 'title' => 'Gültig', 'assignee' => null, 'due_date' => null, 'completed' => false],
        ],
        'outline' => [
            ['id' => '01'], // weder title noch description
            ['id' => '02', 'title' => '**Gültig**', 'description' => ''],
            ['id' => '03', 'description' => 'Auch gültig'],
        ],
    ]);
    assertSame(1, count($result->tasks));
    assertSame(2, count($result->outline));
    assertSame('**Gültig**', $result->outline[0]['title']);
    assertSame('Auch gültig', $result->outline[1]['description']);
});

// ---------------------------------------------------------------- AudioUploadHandler

check('AudioUploadHandler: speichert erlaubte Datei', function (): void {
    $dir = sys_get_temp_dir() . '/gpt-apiv2-uploads-' . bin2hex(random_bytes(4));
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, 'FAKE-MP3-DATA');

    $handler = new AudioUploadHandler($dir, 200);
    $upload = $handler->store(['name' => 'test.mp3', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 13]);

    assertTrue(is_file($upload['path']), 'Datei sollte gespeichert sein');
    assertSame('audio/mpeg', $upload['mime']);
    assertSame('FAKE-MP3-DATA', file_get_contents($upload['path']));

    unlink($upload['path']);
    rmdir($dir);
});

check('AudioUploadHandler: lehnt falsche Erweiterung ab (415)', function (): void {
    $handler = new AudioUploadHandler(sys_get_temp_dir(), 200);
    expectApiException('unsupported_media_type', 415, function () use ($handler): void {
        $handler->store(['name' => 'evil.exe', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 10]);
    });
});

check('AudioUploadHandler: lehnt zu große Datei ab (413)', function (): void {
    $handler = new AudioUploadHandler(sys_get_temp_dir(), 1);
    expectApiException('file_too_large', 413, function () use ($handler): void {
        $handler->store(['name' => 'big.mp3', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 1024 * 1024 + 1]);
    });
});

check('AudioUploadHandler: INI-Größenfehler → 413', function (): void {
    $handler = new AudioUploadHandler(sys_get_temp_dir(), 200);
    expectApiException('file_too_large', 413, function () use ($handler): void {
        $handler->store(['name' => 'a.mp3', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]);
    });
});

// ---------------------------------------------------------------- Request::path

check('Request: Pfad-Auflösung im Document-Root', function (): void {
    $serverBackup = $_SERVER;
    try {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/api/transcribe';
        assertSame('/api/transcribe', (new \App\Http\Request())->path());
        $_SERVER['REQUEST_URI'] = '/index.php/api/health';
        assertSame('/api/health', (new \App\Http\Request())->path());
    } finally {
        $_SERVER = $serverBackup;
    }
});

check('Request: Pfad-Auflösung in Unterverzeichnis-Installation', function (): void {
    $serverBackup = $_SERVER;
    try {
        // Aufruf über /gptapi/public/index.php (Apache, Projekt in Unterverzeichnis)
        $_SERVER['SCRIPT_NAME'] = '/gptapi/public/index.php';
        $_SERVER['REQUEST_URI'] = '/gptapi/public/index.php';
        assertSame('/', (new \App\Http\Request())->path());

        // Per Rewrite: /gptapi/public/api/health
        $_SERVER['REQUEST_URI'] = '/gptapi/public/api/health';
        assertSame('/api/health', (new \App\Http\Request())->path());

        // PATH_INFO-Stil: /gptapi/public/index.php/api/transcribe
        $_SERVER['REQUEST_URI'] = '/gptapi/public/index.php/api/transcribe';
        assertSame('/api/transcribe', (new \App\Http\Request())->path());

        // Regex-Fallback: Skriptname mitten im Pfad, SCRIPT_NAME stimmt nicht
        $_SERVER['SCRIPT_NAME'] = '/gptapi/public/api/health'; // kaputt/ungewöhnlich
        $_SERVER['REQUEST_URI'] = '/gptapi/public/index.php/api/health';
        assertSame('/api/health', (new \App\Http\Request())->path());

        // Regex-Fallback: URI ohne Script-Name-Korrespondenz
        $_SERVER['SCRIPT_NAME'] = '/irgendwas/anderes.php';
        $_SERVER['REQUEST_URI'] = '/gptapi/public/index.php/api/transcribe';
        assertSame('/api/transcribe', (new \App\Http\Request())->path());
    } finally {
        $_SERVER = $serverBackup;
    }
});

// ---------------------------------------------------------------- AudioChunker

check('AudioChunker: FFmpeg-Verfügbarkeit prüfen', function (): void {
    $available = AudioChunker::isAvailable();
    assertTrue(is_bool($available), 'isAvailable() muss bool zurückgeben');
    // Im Codespace sollte FFmpeg verfügbar sein (wurde installiert)
    if (!$available) {
        echo "    ⚠ FFmpeg nicht verfügbar – Chunking-Tests übersprungen\n";
    }
});

check('AudioChunker: Dauer ermitteln', function (): void {
    if (!AudioChunker::isAvailable()) {
        return; // Skip wenn FFmpeg fehlt
    }
    $chunker = new AudioChunker(sys_get_temp_dir());
    // Erstelle eine 2-Sekunden-Testdatei mit ffmpeg
    $testFile = sys_get_temp_dir() . '/test-duration.mp3';
    exec('ffmpeg -y -f lavfi -i sine=frequency=1000:duration=2 -q:a 9 ' . escapeshellarg($testFile) . ' 2>/dev/null');
    if (!is_file($testFile)) {
        throw new RuntimeException('Konnte Test-Audio nicht erstellen');
    }
    $duration = $chunker->getDuration($testFile);
    assertTrue($duration >= 1.9 && $duration <= 2.1, "Dauer sollte ~2s sein, ist {$duration}s");
    unlink($testFile);
});

check('AudioChunker: kurze Datei → kein Chunking', function (): void {
    if (!AudioChunker::isAvailable()) {
        return;
    }
    $chunker = new AudioChunker(sys_get_temp_dir());
    $testFile = sys_get_temp_dir() . '/test-short.mp3';
    exec('ffmpeg -y -f lavfi -i sine=frequency=1000:duration=10 -q:a 9 ' . escapeshellarg($testFile) . ' 2>/dev/null');
    $chunks = $chunker->chunk($testFile, 'audio/mpeg');
    assertSame(1, count($chunks), 'Kurze Datei sollte 1 Chunk ergeben');
    assertSame($testFile, $chunks[0]['path'], 'Chunk sollte Original-Pfad sein');
    unlink($testFile);
});

// ---------------------------------------------------------------- Router

check('Request: erkennt von PHP verworfenen Body (post_max_size)', function (): void {
    $serverBackup = $_SERVER;
    $filesBackup = $_FILES;
    $postBackup = $_POST;
    try {
        $_SERVER['CONTENT_LENGTH'] = '50000000';
        $_FILES = [];
        $_POST = [];
        assertTrue((new \App\Http\Request())->bodyDroppedByPhp(), 'leerer Body bei Content-Length > 0');

        $_SERVER['CONTENT_LENGTH'] = '0';
        assertTrue(!(new \App\Http\Request())->bodyDroppedByPhp(), 'kein Body gesendet');

        $_SERVER['CONTENT_LENGTH'] = '50000000';
        $_FILES = ['file' => ['name' => 'a.mp3', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 1]];
        assertTrue(!(new \App\Http\Request())->bodyDroppedByPhp(), 'Datei korrekt angekommen');
    } finally {
        $_SERVER = $serverBackup;
        $_FILES = $filesBackup;
        $_POST = $postBackup;
    }
});

// ---------------------------------------------------------------- ApiKeyAuth

check('ApiKeyAuth: kein Key konfiguriert → kein Schutz', function (): void {
    $auth = new ApiKeyAuth('');
    $auth->authenticate(); // sollte nicht werfen
    assertTrue(true);
});

check('ApiKeyAuth: gültiger Key im Header', function (): void {
    $_SERVER['HTTP_X_API_KEY'] = 'test-key-123';
    $auth = new ApiKeyAuth('test-key-123');
    $auth->authenticate(); // sollte nicht werfen
    unset($_SERVER['HTTP_X_API_KEY']);
    assertTrue(true);
});

check('ApiKeyAuth: gültiger Key im Query-Parameter', function (): void {
    $_GET['api_key'] = 'test-key-456';
    $auth = new ApiKeyAuth('test-key-456');
    $auth->authenticate(); // sollte nicht werfen
    unset($_GET['api_key']);
    assertTrue(true);
});

check('ApiKeyAuth: fehlender Key → 401', function (): void {
    $auth = new ApiKeyAuth('test-key-789');
    try {
        $auth->authenticate();
        throw new \RuntimeException('Sollte ApiException werfen');
    } catch (ApiException $e) {
        assertSame('unauthorized', $e->errorCode());
        assertSame(401, $e->httpStatus());
    }
});

check('ApiKeyAuth: ungültiger Key → 403', function (): void {
    $_SERVER['HTTP_X_API_KEY'] = 'falscher-key';
    $auth = new ApiKeyAuth('test-key-000');
    try {
        $auth->authenticate();
        throw new \RuntimeException('Sollte ApiException werfen');
    } catch (ApiException $e) {
        assertSame('forbidden', $e->errorCode());
        assertSame(403, $e->httpStatus());
    } finally {
        unset($_SERVER['HTTP_X_API_KEY']);
    }
});

// ---------------------------------------------------------------- OpenAIClient (Sprecher-Formatierung)

check('TranscribeController: Zeitstempel aus Dateiname parsen', function (): void {
    $controller = new \App\Controller\TranscribeController(
        Config::load(APP_ROOT),
        new \App\Http\Request()
    );
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('parseStartTimeFromFilename');
    $method->setAccessible(true);

    // Gültige Formate
    assertSame('2026-09-03 11:30:10', $method->invoke($controller, '2026Sep03-113010-Rec46.mp3'));
    assertSame('2026-09-03 11:30:10', $method->invoke($controller, '2026Sep03-113010-Rec46 - Kopie.mp3'));
    assertSame('2025-12-25 23:59:59', $method->invoke($controller, '2025Dec25-235959-Rec99.mp3'));
    assertSame('2026-01-01 00:00:00', $method->invoke($controller, '2026Jan01-000000-Rec01.mp3'));

    // Ungültige Formate
    assertSame(null, $method->invoke($controller, 'meeting.mp3'));
    assertSame(null, $method->invoke($controller, '2026-09-03.mp3'));
    assertSame(null, $method->invoke($controller, '2026Xyz03-113010.mp3'));
});

check('TranscribeController: ended_at wird korrekt berechnet (keine Zeitzonen-Verschiebung)', function (): void {
    // Simuliere: started_at = 11:00:00, duration = 3600s → ended_at sollte 12:00:00 sein
    $startedAt = '2026-09-03 11:00:00';
    $duration = 3600; // 1 Stunde

    $startedTimestamp = strtotime($startedAt);
    $endedTimestamp = $startedTimestamp + $duration;
    $endedAt = date('Y-m-d H:i:s', $endedTimestamp);

    assertSame('2026-09-03 12:00:00', $endedAt, 'ended_at sollte 12:00 sein, nicht 13:00 oder 14:00');
});

// Datenbank-Tests nur ausführen, wenn DB verfügbar ist
$dbAvailable = false;
try {
    $dbAvailable = Database::isAvailable();
} catch (\Throwable) {
    $dbAvailable = false;
}

if ($dbAvailable) {
    check('Database: Verbindung herstellen', function (): void {
        $db = Database::getConnection();
        assertTrue($db instanceof \mysqli, 'Sollte mysqli-Instanz sein');
        assertTrue($db->ping(), 'Verbindung sollte aktiv sein');
    });

    check('MeetingRepository: exists() für nicht-existente ID', function (): void {
        $repo = new MeetingRepository();
        assertTrue(!$repo->exists('test_nonexistent_' . bin2hex(random_bytes(8))), 'Sollte false für nicht-existente ID sein');
    });
} else {
    echo "⚠ Datenbank nicht verfügbar – DB-Tests übersprungen\n";
}

check('OpenAIClient: Sprecher-Formatierung aus Segmenten', function (): void {
    $client = new \App\OpenAI\OpenAIClient('test-key');
    $reflection = new ReflectionClass($client);
    $method = $reflection->getMethod('formatWithSpeakers');
    $method->setAccessible(true);

    $segments = [
        ['speaker' => 'A', 'speaker_name' => 'Oliver', 'text' => 'Guten Morgen zusammen.'],
        ['speaker' => 'A', 'speaker_name' => 'Oliver', 'text' => 'Schön dass ihr da seid.'],
        ['speaker' => 'B', 'text' => 'Danke für die Einladung.'],
        ['speaker' => 'A', 'speaker_name' => 'Oliver', 'text' => 'Fangen wir an.'],
    ];

    $result = $method->invoke($client, $segments);

    assertTrue(str_contains($result, '**Oliver:**'), 'speaker_name sollte verwendet werden');
    assertTrue(str_contains($result, '**B:**'), 'speaker als Fallback');
    assertTrue(str_contains($result, 'Guten Morgen zusammen.'), 'Text sollte enthalten sein');
    assertTrue(substr_count($result, '**Oliver:**') === 2, 'Oliver sollte 2x markiert sein (Wechsel)');
    assertTrue(!str_contains($result, '**A:**'), 'speaker sollte nicht verwendet werden wenn speaker_name vorhanden');
});

check('Router: registrierte Route wird aufgerufen', function (): void {
    $router = new Router();
    $called = false;
    $router->add('GET', '/api/health', function () use (&$called): void {
        $called = true;
    });
    $router->dispatch('GET', '/api/health');
    assertTrue($called);
});

check('Router: unbekannte Route → 404', function (): void {
    $router = new Router();
    $router->add('GET', '/api/health', function (): void {
    });
    ob_start();
    $router->dispatch('GET', '/gibts-nicht');
    $output = (string) ob_get_clean();
    $data = json_decode($output, true);
    assertSame('not_found', $data['error']['code'] ?? null);
});

check('Router: falsche Methode → 405', function (): void {
    $router = new Router();
    $router->add('GET', '/api/health', function (): void {
    });
    ob_start();
    $router->dispatch('POST', '/api/health');
    $output = (string) ob_get_clean();
    $data = json_decode($output, true);
    assertSame('method_not_allowed', $data['error']['code'] ?? null);
});

// ---------------------------------------------------------------- WebController

check('WebController: GET / liefert die UI-Seite', function (): void {
    $router = new Router();
    $router->add('GET', '/', new \App\Controller\WebController(Config::load(APP_ROOT)));
    ob_start();
    $router->dispatch('GET', '/');
    $html = (string) ob_get_clean();
    assertTrue(str_contains($html, '<form'), 'UI sollte ein Formular enthalten');
    assertTrue(str_contains($html, "'index.php/api'"), 'UI sollte PATH_INFO-API-Basis nutzen');
    assertTrue(str_contains($html, '/transcribe'), 'UI sollte den Transcribe-Endpunkt aufrufen');
    assertTrue(str_contains($html, '/health'), 'UI sollte den Health-Endpunkt abfragen');
});

// ---------------------------------------------------------------- Ergebnis

$total = 0;
echo "\n" . ($failures === 0 ? '✅ Alle Tests bestanden.' : "❌ {$failures} Test(s) fehlgeschlagen.") . "\n";
exit($failures === 0 ? 0 : 1);

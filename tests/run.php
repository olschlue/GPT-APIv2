<?php

declare(strict_types=1);

/**
 * Eigenständiger Test-Runner ohne externe Abhängigkeiten.
 * Aufruf: php tests/run.php  (oder: composer test)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Analysis\AnalysisResult;
use App\Config;
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
        'outline' => [['topic' => 'Budget', 'points' => ['Kosten steigen']]],
        'tasks' => [['task' => 'Report erstellen', 'owner' => 'Anna', 'deadline' => '2026-09-10', 'priority' => 'high']],
        'decisions' => ['Launch im Oktober'],
        'extra' => 'wird verworfen',
    ]));
    assertSame('Kurzfassung', $result->summary);
    assertSame('Budget', $result->outline[0]['topic']);
    assertSame(['Kosten steigen'], $result->outline[0]['points']);
    assertSame('Anna', $result->tasks[0]['owner']);
    assertSame('high', $result->tasks[0]['priority']);
    assertSame(['Launch im Oktober'], $result->decisions);
});

check('AnalysisResult: ungültige Priorität → medium', function (): void {
    $result = AnalysisResult::fromArray([
        'tasks' => [['task' => 'X', 'priority' => 'super-wichtig']],
    ]);
    assertSame('medium', $result->tasks[0]['priority']);
    assertSame('', $result->tasks[0]['owner']);
    assertSame('', $result->tasks[0]['deadline']);
});

check('AnalysisResult: fehlende Felder → Defaults', function (): void {
    $result = AnalysisResult::fromArray([]);
    assertSame('', $result->summary);
    assertSame([], $result->outline);
    assertSame([], $result->tasks);
    assertSame([], $result->decisions);
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

check('AnalysisResult: Einträge ohne task/topic werden übersprungen', function (): void {
    $result = AnalysisResult::fromArray([
        'tasks' => [['owner' => 'Niemand'], ['task' => 'Gültig']],
        'outline' => [['points' => ['x']], ['topic' => 'Gültig', 'points' => 'kein-array']],
    ]);
    assertSame(1, count($result->tasks));
    assertSame(1, count($result->outline));
    assertSame([], $result->outline[0]['points']);
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

// ---------------------------------------------------------------- Router

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
    assertTrue(str_contains($html, '/api/transcribe'), 'UI sollte den Transcribe-Endpunkt aufrufen');
    assertTrue(str_contains($html, '/api/health'), 'UI sollte den Health-Endpunkt abfragen');
});

// ---------------------------------------------------------------- Ergebnis

$total = 0;
echo "\n" . ($failures === 0 ? '✅ Alle Tests bestanden.' : "❌ {$failures} Test(s) fehlgeschlagen.") . "\n";
exit($failures === 0 ? 0 : 1);

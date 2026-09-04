<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Http\ApiException;

/**
 * Normalisiert und validiert die Antwort des Analyse-Modells.
 * Garantiert die exakte Ausgabestruktur: summary, outline, tasks, decisions.
 */
final class AnalysisResult
{
    /**
     * @param array<int, array{topic: string, points: array<int, string>}> $outline
     * @param array<int, array{task: string, owner: string, deadline: string, priority: string}> $tasks
     * @param array<int, string> $decisions
     */
    private function __construct(
        public readonly string $summary,
        public readonly array $outline,
        public readonly array $tasks,
        public readonly array $decisions,
    ) {
    }

    /**
     * @throws ApiException wenn der String kein JSON-Objekt enthält
     */
    public static function fromJson(string $json): self
    {
        $clean = self::stripCodeFences($json);
        $data = json_decode($clean, true);
        if (!is_array($data)) {
            throw new ApiException('analysis_invalid_json', 'Das Analyse-Modell lieferte kein valides JSON.', 502);
        }
        return self::fromArray($data);
    }

    /**
     * Nimmt Rohdaten entgegen und normalisiert sie auf das exakte Zielschema.
     * Unbekannte Felder werden verworfen, fehlende mit Defaults ergänzt.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            summary: self::str($data, 'summary'),
            outline: self::outline($data['outline'] ?? []),
            tasks: self::tasks($data['tasks'] ?? []),
            decisions: self::decisions($data['decisions'] ?? []),
        );
    }

    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    /** @return array<int, array{id: string, title?: string, description: string}> */
    private static function outline(mixed $items): array
    {
        $outline = [];
        if (!is_array($items)) {
            return $outline;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            // ID generieren, falls nicht vorhanden
            $id = $item['id'] ?? bin2hex(random_bytes(16));

            // Eintrag hat entweder title+description oder nur description
            $entry = ['id' => (string) $id];

            if (isset($item['title']) && is_string($item['title']) && $item['title'] !== '') {
                $entry['title'] = $item['title'];
            }

            $description = $item['description'] ?? '';
            if (is_string($description)) {
                $entry['description'] = $description;
            } else {
                $entry['description'] = '';
            }

            // Eintrag muss entweder title oder description haben
            if (isset($entry['title']) || $entry['description'] !== '') {
                $outline[] = $entry;
            }
        }
        return $outline;
    }

    /** @return array<int, array{id: string, title: string, assignee: null|array, due_date: null|string, completed: bool}> */
    private static function tasks(mixed $items): array
    {
        $tasks = [];
        if (!is_array($items)) {
            return $tasks;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = self::str($item, 'title');
            if ($title === '') {
                continue;
            }

            // ID generieren, falls nicht vorhanden
            $id = $item['id'] ?? bin2hex(random_bytes(16));

            // assignee: null oder Objekt (wird vom externen System befüllt)
            $assignee = null;
            if (isset($item['assignee']) && is_array($item['assignee'])) {
                $assignee = $item['assignee'];
            }

            // due_date: ISO-8601-String oder null
            $dueDate = null;
            if (isset($item['due_date']) && is_string($item['due_date']) && $item['due_date'] !== '') {
                $dueDate = $item['due_date'];
            }

            $tasks[] = [
                'id' => (string) $id,
                'title' => $title,
                'assignee' => $assignee,
                'due_date' => $dueDate,
                'completed' => false,
            ];
        }
        return $tasks;
    }

    /** @return array<int, array{id: string, description: string}> */
    private static function decisions(mixed $items): array
    {
        $decisions = [];
        if (!is_array($items)) {
            return $decisions;
        }
        foreach ($items as $item) {
            // Unterstütze sowohl Strings als auch Objekte
            if (is_string($item) && $item !== '') {
                // String → in Objekt umwandeln
                $decisions[] = [
                    'id' => bin2hex(random_bytes(16)),
                    'description' => $item,
                ];
            } elseif (is_array($item)) {
                $description = self::str($item, 'description');
                if ($description !== '') {
                    $id = $item['id'] ?? bin2hex(random_bytes(16));
                    $decisions[] = [
                        'id' => (string) $id,
                        'description' => $description,
                    ];
                }
            }
        }
        return $decisions;
    }

    private static function priority(mixed $value): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($value, ['low', 'medium', 'high'], true) ? $value : 'medium';
    }

    /**
     * Entfernt optionale Markdown-Code-Fences (```json ... ```), falls das Modell sie trotz JSON-Modus sendet.
     */
    private static function stripCodeFences(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $matches) === 1) {
            return $matches[1];
        }
        return $text;
    }
}

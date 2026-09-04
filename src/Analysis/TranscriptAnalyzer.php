<?php

declare(strict_types=1);

namespace App\Analysis;

use App\OpenAI\OpenAIClient;

/**
 * Wertet ein Transkript mit dem Analyse-Modell aus und liefert ein
 * normalisiertes Ergebnis mit exakt den Feldern summary, outline, tasks, decisions.
 */
final class TranscriptAnalyzer
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Du bist ein Assistent, der Transkripte von Meetings, Interviews und Gesprächen strukturiert auswertet.
Antworte ausschließlich mit einem validen JSON-Objekt mit exakt diesen Feldern:

{
  "summary": "prägnante Zusammenfassung des Inhalts in 3-5 Sätzen",
  "outline": [
    {"topic": "Themenblock", "points": ["Kernpunkt 1", "Kernpunkt 2"]}
  ],
  "tasks": [
    {"task": "konkrete Aufgabe", "owner": "verantwortliche Person oder leer", "deadline": "ISO-Datum oder leer", "priority": "low|medium|high"}
  ],
  "decisions": [
    "im Gespräch getroffene Entscheidung"
  ]
}

Regeln:
- Keine zusätzlichen Felder, kein Markdown, keine Erklärungen – nur das JSON-Objekt.
- Schreibe in der Sprache des Transkripts.
- Wenn es keine Tasks oder Decisions gibt, liefere leere Arrays.
PROMPT;

    public function __construct(private readonly OpenAIClient $client)
    {
    }

    public function analyze(string $transcript): AnalysisResult
    {
        $json = $this->client->analyzeJson(self::SYSTEM_PROMPT, "Transkript:\n" . $transcript);
        return AnalysisResult::fromJson($json);
    }
}

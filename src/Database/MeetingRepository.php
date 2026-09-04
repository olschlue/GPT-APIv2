<?php

declare(strict_types=1);

namespace App\Database;

use mysqli;

/**
 * Repository für krisp_meetings-Tabelle.
 */
final class MeetingRepository
{
    private mysqli $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Speichert ein Meeting mit Transkript und Analyse.
     *
     * @param array<string, mixed> $data
     * @return int Die ID des eingefügten Datensatzes
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO krisp_meetings (
                krisp_meeting_id,
                title,
                customer,
                meeting_url,
                started_at,
                ended_at,
                duration_seconds,
                transcript,
                transcript_updated_at,
                summary,
                notes,
                outline,
                action_items,
                key_points,
                recording,
                recording_url,
                summary_updated_at,
                last_event_type,
                last_event_id,
                raw_payload,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        if ($stmt === false) {
            throw new \RuntimeException('Prepare fehlgeschlagen: ' . $this->db->error);
        }

        $stmt->bind_param(
            'ssssssisssssssssssss',
            $data['krisp_meeting_id'],
            $data['title'],
            $data['customer'],
            $data['meeting_url'],
            $data['started_at'],
            $data['ended_at'],
            $data['duration_seconds'],
            $data['transcript'],
            $data['transcript_updated_at'],
            $data['summary'],
            $data['notes'],
            $data['outline'],
            $data['action_items'],
            $data['key_points'],
            $data['recording'],
            $data['recording_url'],
            $data['summary_updated_at'],
            $data['last_event_type'],
            $data['last_event_id'],
            $data['raw_payload']
        );

        if (!$stmt->execute()) {
            throw new \RuntimeException('Insert fehlgeschlagen: ' . $stmt->error);
        }

        $id = $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Aktualisiert ein bestehendes Meeting (falls krisp_meeting_id bereits existiert).
     *
     * @param array<string, mixed> $data
     */
    public function update(array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE krisp_meetings SET
                title = ?,
                customer = ?,
                duration_seconds = ?,
                transcript = ?,
                transcript_updated_at = ?,
                summary = ?,
                notes = ?,
                outline = ?,
                action_items = ?,
                key_points = ?,
                summary_updated_at = ?,
                raw_payload = ?,
                updated_at = NOW()
            WHERE krisp_meeting_id = ?
        ");

        if ($stmt === false) {
            throw new \RuntimeException('Prepare fehlgeschlagen: ' . $this->db->error);
        }

        $stmt->bind_param(
            'ssissssssssss',
            $data['title'],
            $data['customer'],
            $data['duration_seconds'],
            $data['transcript'],
            $data['transcript_updated_at'],
            $data['summary'],
            $data['notes'],
            $data['outline'],
            $data['action_items'],
            $data['key_points'],
            $data['summary_updated_at'],
            $data['raw_payload'],
            $data['krisp_meeting_id']
        );

        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Prüft, ob ein Meeting mit dieser krisp_meeting_id existiert.
     */
    public function exists(string $krispMeetingId): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM krisp_meetings WHERE krisp_meeting_id = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('s', $krispMeetingId);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        return $exists;
    }
}

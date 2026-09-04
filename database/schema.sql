-- Schema für krisp_meetings
-- Ausführen mit: mysql -u krisp -p krisp < database/schema.sql

CREATE TABLE IF NOT EXISTS krisp_meetings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    krisp_meeting_id VARCHAR(255) NOT NULL UNIQUE COMMENT 'Externe Meeting-ID (z. B. aus Krisp)',
    title VARCHAR(500) NULL COMMENT 'Meeting-Titel (generiert oder aus Analyse)',
    customer VARCHAR(255) NULL COMMENT 'Kunde/Projekt (aus Transkript ableitbar)',
    meeting_url VARCHAR(1000) NULL COMMENT 'URL zur Aufnahme oder zum Meeting',
    started_at DATETIME NULL COMMENT 'Start-Zeitpunkt des Meetings',
    ended_at DATETIME NULL COMMENT 'End-Zeitpunkt des Meetings',
    duration_seconds INT UNSIGNED NULL COMMENT 'Dauer in Sekunden (aus Transkription)',
    transcript MEDIUMTEXT NULL COMMENT 'Vollständiges Transkript',
    transcript_updated_at DATETIME NULL COMMENT 'Zeitpunkt der Transkription',
    summary TEXT NULL COMMENT 'Zusammenfassung (Analyse-Modell)',
    notes MEDIUMTEXT NULL COMMENT 'Ausführliche Notizen (Analyse-Modell)',
    outline TEXT NULL COMMENT 'Strukturierte Gliederung als JSON (Analyse-Modell)',
    action_items JSON NULL COMMENT 'Aufgaben/To-dos als JSON (Analyse-Modell)',
    key_points JSON NULL COMMENT 'Entscheidungen, Risiken, offene Fragen als JSON (Analyse-Modell)',
    recording JSON NULL COMMENT 'Aufnahme-Metadaten als JSON (id, size, duration, mime_type, filename, recording_url)',
    recording_url TEXT NULL COMMENT 'URL zur Aufnahme (lokal oder extern)',
    summary_updated_at DATETIME NULL COMMENT 'Zeitpunkt der letzten Analyse',
    last_event_type VARCHAR(100) NULL COMMENT 'Letzter Event-Typ (z. B. transcription.completed)',
    last_event_id VARCHAR(255) NULL COMMENT 'Letzte Event-ID',
    raw_payload JSON NULL COMMENT 'Komplette Roh-Response der OpenAI API als JSON',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_krisp_meeting_id (krisp_meeting_id),
    INDEX idx_created_at (created_at),
    INDEX idx_customer (customer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Meeting-Transkripte und Analysen aus OpenAI API';

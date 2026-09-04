-- Fix für krisp_meetings.recording: VARCHAR statt JSON
-- Ausführen mit: mysql -u krisp -p krisp < database/fix-recording-column.sql

USE krisp;

-- Prüfen, ob Spalte existiert und falsch definiert ist
SET @column_type = (
    SELECT DATA_TYPE 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'krisp' 
    AND TABLE_NAME = 'krisp_meetings' 
    AND COLUMN_NAME = 'recording'
);

-- Wenn JSON, dann zu VARCHAR ändern
SET @sql = IF(
    @column_type = 'json',
    'ALTER TABLE krisp_meetings MODIFY COLUMN recording VARCHAR(255) NULL COMMENT ''Aufnahme-Dateiname oder -Pfad''',
    'SELECT "Spalte recording ist bereits korrekt (VARCHAR)" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ergebnis anzeigen
SELECT 
    COLUMN_NAME, 
    DATA_TYPE, 
    CHARACTER_MAXIMUM_LENGTH,
    IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'krisp' 
AND TABLE_NAME = 'krisp_meetings' 
AND COLUMN_NAME = 'recording';

-- Apply after request_enhancements_migration.sql and before deploying representative requests.
-- The column check allows a safe retry if an earlier attempt added it before stopping.
SET @representative_column_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_request' AND COLUMN_NAME = 'owner_relationship_description'),
    'SELECT 1',
    'ALTER TABLE document_request ADD COLUMN owner_relationship_description VARCHAR(120) DEFAULT NULL'
);
PREPARE representative_column_stmt FROM @representative_column_sql;
EXECUTE representative_column_stmt;
DEALLOCATE PREPARE representative_column_stmt;

CREATE TABLE IF NOT EXISTS request_representative_document (
    representative_document_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    document_key VARCHAR(50) NOT NULL,
    display_name VARCHAR(180) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    file_format VARCHAR(10) NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_request_representative_document (request_id, document_key),
    UNIQUE KEY uq_representative_document_path (file_path),
    INDEX idx_representative_document_request (request_id, uploaded_at),
    CONSTRAINT fk_representative_document_request FOREIGN KEY (request_id)
        REFERENCES document_request (request_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Align the business-service label used by the public portal and fresh installs.
-- Leave any pre-existing canonical row untouched rather than merging document types.
UPDATE document_type AS legacy_type
LEFT JOIN document_type AS canonical_type
    ON canonical_type.document_type_id <> legacy_type.document_type_id
    AND LOWER(TRIM(canonical_type.name)) = 'barangay business clearance'
SET legacy_type.name = 'Barangay Business Clearance'
WHERE LOWER(TRIM(legacy_type.name)) = 'business permit'
  AND canonical_type.document_type_id IS NULL;

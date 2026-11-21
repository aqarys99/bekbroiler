ALTER TABLE remember_tokens ADD COLUMN prev_hash TEXT NULL;
ALTER TABLE remember_tokens ADD COLUMN rotated_at TEXT NULL;

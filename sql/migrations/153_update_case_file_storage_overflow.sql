-- InfinityFree/local is the primary case-document provider; Supabase is overflow.
ALTER TABLE `case_file_documents`
  MODIFY COLUMN `storage_provider` VARCHAR(30) NOT NULL DEFAULT 'local';

-- Existing records keep their provider. This migration intentionally does not move
-- existing documents or delete Supabase objects.

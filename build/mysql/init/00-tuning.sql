-- Prosper202 MySQL initialization.
-- This runs once on first container start when db-data volume is empty.

-- Ensure the database uses the right character set.
ALTER DATABASE prosper202 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

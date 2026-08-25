-- Runs once, on a fresh database, as the container's bootstrap superuser
-- (docker-entrypoint-initdb.d convention). On the real production server the
-- app's own DB role deliberately isn't a superuser, so this same statement is
-- run out-of-band by an admin instead (see the knowledge_chunks embedding
-- migration's own docblock) -- here the compose Postgres user is both, which
-- is fine for a portable/local stack.
CREATE EXTENSION IF NOT EXISTS vector;

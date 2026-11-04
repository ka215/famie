\set ON_ERROR_STOP on

-- Run only on the newly initialized, isolated test instance (port 55432).
-- This script deliberately fails if the role or database already exists.
DO $$
BEGIN
    IF current_setting('port') <> '55432'
        OR current_user <> 'famie_test_admin'
        OR current_database() <> 'postgres'
        OR EXISTS (SELECT 1 FROM pg_database WHERE datname NOT IN ('postgres', 'template0', 'template1')) THEN
        RAISE EXCEPTION 'A fresh isolated test instance on port 55432 is required';
    END IF;
END
$$;

CREATE ROLE famie_test_runner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE DATABASE famie_test OWNER famie_test_runner;
REVOKE CONNECT ON DATABASE postgres, template1 FROM PUBLIC;
\connect famie_test
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT ALL ON SCHEMA public TO famie_test_runner;
\password famie_test_runner

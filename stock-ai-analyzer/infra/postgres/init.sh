#!/bin/sh
# Runs once, on first initialization of the Postgres volume.
# Creates the extensions we need and a separate database for the test suite.
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
    CREATE EXTENSION IF NOT EXISTS pg_trgm;
    CREATE DATABASE ${POSTGRES_DB}_testing OWNER ${POSTGRES_USER};
SQL

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "${POSTGRES_DB}_testing" <<-SQL
    CREATE EXTENSION IF NOT EXISTS pg_trgm;
SQL

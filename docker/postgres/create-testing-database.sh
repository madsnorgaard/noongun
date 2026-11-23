#!/bin/sh
# Creates the database used by the Pest suite (see phpunit.xml).
# Runs once, when the postgres volume is first initialised.
set -e

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
CREATE DATABASE noongun_testing OWNER "$POSTGRES_USER";
SQL

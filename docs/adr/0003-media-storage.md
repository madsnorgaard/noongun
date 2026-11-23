# 0003 - Media storage: local disk first, S3-compatible later

Date: 2026-11-23. Status: accepted.

## Decision

Phase 1 stores media on a local Laravel filesystem disk on a Docker volume.
Phase 3 switches to an S3-compatible disk by configuration only. The `media` table
stores `disk` and `path`, never absolute URLs, so both work from the same code.

## Consequences

Backups in Phase 1 must include the media volume. No code may assume a local path.

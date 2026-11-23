# 0004 - Our scheduler is the only scheduling authority

Date: 2026-11-23. Status: accepted.

## Context

Facebook and LinkedIn both offer native scheduled posts (for Facebook,
`published=false` with `scheduled_publish_time`).

## Decision

Noon Gun never uses platform-native scheduling. Posts are held in our database and
published at their time by our scheduler and `PublishPostJob`.

## Consequences

Approval, cancellation, editing and the audit trail live in one place. If the
server is down at the scheduled minute the post goes out late, so alerts for a
stalled queue or scheduler are required before Phase 1 is done.

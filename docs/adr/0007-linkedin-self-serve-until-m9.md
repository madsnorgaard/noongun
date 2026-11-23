# 0007 - LinkedIn: self-serve products only until M9

Date: 2026-11-23. Status: accepted.

## Context

The brief (section 8) says to apply for the LinkedIn Community Management API
early. LinkedIn accepts that application only from registered legal
organisations, with legal name, address and a verified business email, and a
rejected application cannot be resubmitted. ADR 0005 defers the legal entity to M9.
Findings are in `docs/platform-notes/linkedin.md`.

## Decision

Until M9, Noon Gun uses only the self-serve products "Share on LinkedIn" and
"Sign In with LinkedIn using OpenID Connect". The Community Management API
application is made in M9, once the legal entity is chosen.

## Consequences

- M5 posts to personal profiles only. No company page posting before M9.
- M6 insights cover Facebook only. Personal post metrics need
  `r_member_postAnalytics`, which only the Community Management API grants.
- Self-serve apps get no refresh tokens. LinkedIn access tokens last 60 days, so
  M7 must alert before expiry and offer a one-click reconnect.
- Phase 2 "metrics feed back into drafting" applies to Facebook only until M9.

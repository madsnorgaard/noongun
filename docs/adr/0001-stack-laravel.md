# 0001 - Laravel as the application stack

Date: 2026-11-23. Status: accepted.

## Context

The brief (section 13.1) assumes Laravel, with Symfony or Python as alternatives.
The closest reference project, Mixpost Lite, is Laravel, Vue and Inertia.

## Decision

Use PHP and Laravel with Inertia, Vue 3 and Tailwind, PostgreSQL, Redis, Horizon
and the Laravel scheduler, as listed in `CLAUDE.md`.

## Consequences

Mixpost Lite patterns apply directly. Prism, Socialite, Cashier and Horizon cover
the LLM, OAuth, billing and queue needs without custom infrastructure.

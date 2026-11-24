# 0006 - Pinned versions for M2

Date: 2026-11-23. Status: accepted.

## Context

CLAUDE.md asks for the current stable version of each dependency, checked at
the time of pinning rather than taken from memory. Versions were looked up on
2026-11-23 from the sources listed below.

## Decision

| Component | Version | Source | Note |
|---|---|---|---|
| PHP | 8.5.13 | php.net releases JSON | 8.6.0 is newest (19 Nov 2026), see below |
| Web server | FrankenPHP 1.14.0 (Caddy 2.11.9) | Docker Hub `dunglas/frankenphp`, GitHub releases | Image `dunglas/frankenphp:1.14-php8.5-alpine` |
| Composer | 2.11.2 | Docker Hub `composer` | |
| Laravel | 13.43.0 | Packagist `laravel/framework` | |
| Starter kit | `laravel/vue-starter-kit` main, 2026-11-10 | GitHub | Fortify, Inertia, Wayfinder |
| inertiajs/inertia-laravel | 3.7.0 | Packagist | |
| @inertiajs/vue3 | 3.11.0 | npm | |
| Vue | 3.5.46 | npm | |
| Tailwind CSS | 4.3.5 | npm | |
| Laravel Horizon | 5.52.0 | Packagist | |
| Laravel Fortify | 1.43.0 | Packagist | |
| Pest | 5.3.7 (PHPUnit 13.4.7) | Packagist | pest-plugin-laravel 5.0.1 |
| Larastan | 3.15.0 (PHPStan 2.3.7) | Packagist | Level 7 |
| Pint | 1.32.3 | Packagist | Laravel preset |
| PostgreSQL | 19.0 | postgresql.org versions feed, Docker Hub | Image `postgres:19-alpine` |
| Redis | 8.10.3 | GitHub releases, Docker Hub | Image `redis:8.10-alpine` |
| Node.js | 24.22.3 | Alpine 3.24 package | Used only to build assets |

Composer and npm constraints use caret ranges; `composer.lock` and
`package-lock.json` hold the exact versions. Docker images are pinned to a
minor version so security patches arrive on rebuild.

### Exceptions to "newest"

- **PHP 8.5, not 8.6.** PHP 8.6.0 was four days old. The phpredis extension
  (6.3.0) does not compile against it (removed `INI_INT`, `zval_dtor` and
  related macros), and Horizon depends on Redis. Move to 8.6 once a phpredis
  release supports it.
- **Redis 8.10, not 8.12.** Redis 8.12.0 was released on 22 Nov 2026 and had
  no official Docker image yet.
- **Node.js 24, not 26.** Node 26 is the current LTS line. The image uses the
  Alpine package (24, still in LTS maintenance) to avoid copying binaries
  between images. Only the asset build uses Node.
- **Frontend tooling as shipped by the starter kit.** The kit builds with
  Vite+ (`vite-plus` 1.4.0, which replaces `vite`) and TypeScript 5.9.3, while
  plain Vite 8.3.8 and TypeScript 7.0.2 are newer. Changing the kit's build
  chain is not worth the drift from upstream at this point.

### Larastan level

Level 7 is the starter kit default and passes without a baseline. On top of
level 6 (missing types and generics) it reports partially wrong union types,
for example passing `int|null` where `int` is required, which is the kind of
mistake the workspace scoping code must not make. Level 8 (calls on nullable
values) can follow once the domain models exist.

## Consequences

Upgrades are deliberate: bump the version, run `./dev check` and
`./dev migrations`, and update this table.

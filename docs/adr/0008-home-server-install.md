# 0008 - Home server install behind a TLS proxy

Date: 2026-11-24. Status: accepted.

## Context

Phase 1 runs on a home server that is reachable only over Tailscale. `./setup`
gives a development stack: source mounted into the container, debug mode on, the
web port published on every interface. The first install on a server also showed
that the app built `http://` links behind `tailscale serve`, because it did not
trust the proxy's forwarded headers.

## Decision

- A server runs the production image from `docker-compose.yml` alone. `.env` sets
  `COMPOSE_FILE=docker-compose.yml` so the development override is not loaded.
- The web port is published on `127.0.0.1` only, through `NOONGUN_HTTP_PORT`
  (for example `127.0.0.1:8090`). Docker bypasses host firewalls for published
  ports, so the binding is what keeps the port private.
- HTTPS is terminated by a reverse proxy on the same machine. Phase 1 uses
  `tailscale serve`; nothing is exposed to the public internet.
- `NOONGUN_TRUSTED_PROXIES` names the proxies whose `X-Forwarded-*` headers are
  trusted. The default is empty and trusts none.

## Alternatives

- Trust every proxy by default: simpler, but an instance with its port open would
  accept forged scheme and host headers.
- Force HTTPS from `APP_URL`: breaks plain `http://localhost` development and
  hides a wrong proxy setup instead of showing it.
- A production path inside `./setup`: deferred until a second install shows what
  it should automate.

## Consequences

- A server install is a short manual sequence, described in the README under
  "Running on a home server".
- Each instance on a shared host needs its own port, and its own project name and
  session cookie name if two instances share one hostname.
- Backups of the database, the volumes and `.env` are not covered here. They
  belong to M7.

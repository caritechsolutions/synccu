# SyncCU — Architecture & Repository Map

> **Read this first.** This document exists to prevent confusion about which
> code is live, how it is deployed, and where the member (client) portal fits.

_Last updated: 2026-06-30_

---

## 1. TL;DR

- **The live system is the `platform/` tree** on branch
  **`claude/rebuild-platform-modern-5He4J`**. That is what the production
  `update.sh` pulls and deploys.
- **Deployment is NATIVE — there is no Docker.** `platform/install.sh` sets up
  nginx + PHP-FPM + a systemd `uvicorn` service + MySQL directly on the host.
  (The old `platform/docker/` and `docker-compose.yml` were unused and have been
  removed.)
- **`/web/` at the repo root is the LEGACY "SyncCU Web 1.0" app.** It is *not*
  deployed by the platform installer. Treat it as historical/dead unless a
  decision is made to delete it. Do not build new features there.
- **Today's platform is effectively the ADMIN/back-office system.** A member
  (client) portal scaffold exists (`platform/frontend/portal/`) but is
  incomplete and has never been reachable in practice (no `member` accounts have
  ever logged in).

---

## 2. Repository layout

```
synccu/
├── web/                     # LEGACY SyncCU Web 1.0 (PHP). NOT deployed. Do not extend.
├── docs/                    # This documentation.
└── platform/                # THE LIVE SYSTEM (deployed natively, no Docker)
    ├── install.sh           # Native installer (nginx + php-fpm + uvicorn + mysql)
    ├── update.sh            # Native updater (pulls branch tarball, runs migrations)
    ├── backup.sh
    ├── api/                 # Python FastAPI service  → served at /api/v2
    ├── backend/             # PHP API (primary)       → served at /api/v1
    ├── database/            # schema.sql, seed.sql, migrations/
    └── frontend/            # Static HTML/CSS/JS (nginx web root)
        ├── index.html       # Login (currently unified — to be split, see plan)
        ├── change-password.html
        ├── admin/           # Staff back-office UI
        ├── portal/          # MEMBER (client) UI — dashboard, accounts, transfer, loans, profile
        ├── css/  js/  assets/
```

### Which backend is "live"?
- **`/api/v1` → PHP (`platform/backend/`) is the primary API** the frontend
  calls (the login posts to `/api/v1/auth/login`; nginx routes `/api/v1/` to
  PHP-FPM).
- **`/api/v2` → Python FastAPI (`platform/api/`)** runs in parallel as a
  systemd `uvicorn` service. Confirm per-feature which one owns a given route
  before extending it.

---

## 3. How it is served (native, from `install.sh`)

| Concern            | Reality |
|--------------------|---------|
| Web server         | **nginx**, web root = `platform/frontend/` |
| PHP API `/api/v1`  | **php-fpm** via nginx `fastcgi` |
| Python API `/api/v2` | **systemd** unit `synccu-api.service` → `uvicorn main:app` on `127.0.0.1:8000` |
| Database           | **MySQL/MariaDB** on the host |
| TLS                | certbot/Let's Encrypt (`certbot --nginx`) |
| Containers         | **None.** Docker files were removed. |

Member portal is reachable at `https://<host>/portal/dashboard.html`; the login
redirects `role='member'` users there and staff to `/admin/dashboard.html`.

---

## 4. Target topology (where we are heading)

For security, the system is being split into **two network-isolated nodes that
share NO inbound path from public → private**. Full detail and rationale live in
[`CLIENT_PORTAL_BUILD_PLAN.md`](./CLIENT_PORTAL_BUILD_PLAN.md). Summary:

```
            INTERNET                         CU PRIVATE NETWORK (firewalled)
   ┌───────────────────────────┐      ┌───────────────────────────────────┐
   │  CLIENT NODE (public)      │      │  ADMIN/CORE NODE (private)         │
   │  behind Cloudflare         │      │  no public DNS / no open ports     │
   │  • /portal/* + member API  │      │  • /admin/* + full API             │
   │  • local READ projection   │      │  • authoritative MySQL ledger      │
   │  • local WRITE queue        │      │  • runs the SYNC AGENT (initiator) │
   │  wg0 ◀───────────────────────────▶ wg0  (WireGuard mesh)               │
   └───────────────────────────┘      └───────────────────────────────────┘
        ▲  members / mobile apps              core PULLS queued writes,
        │  (HTTPS via Cloudflare)             PUSHES projections + results
```

- **The CORE initiates** all cross-node traffic. The public node never connects
  into the private network.
- **Money never moves on the public node.** Member actions are queued and
  applied authoritatively on the core (idempotent by client-generated UUID).
- The **member API is the single contract** consumed by the web portal **and**
  future Android/iOS apps.

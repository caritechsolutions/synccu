# SyncCU — Client (Member) Portal Build Plan

_Most-secure two-node architecture. Web + future Android/iOS via one shared member API._

_Status: PLAN — awaiting sign-off to start Phase 1. Last updated: 2026-06-30._

---

## 1. Goal

Give members a **public, secure** way to:
1. View account info (balances, accounts, transactions, statements)
2. Transfer funds between their own accounts
3. Send secure messages to admin (and receive replies)
4. Pay bills (manage payees, pay billers, pay own loans)
5. Apply for loans and services

…**without ever exposing the admin back-office to the internet**, and with the
member API designed so a **web portal and native mobile apps** are all just
clients of the same endpoint.

---

## 2. Architecture (agreed)

Two network-isolated nodes, **core-initiated** sync, authoritative ledger stays
private.

| | **Client node (public)** | **Admin/Core node (private)** |
|---|---|---|
| Network | DMZ, behind Cloudflare | CU LAN/VPN, no public DNS, no open ports |
| Serves | `/portal/*` + **member-only** API | `/admin/*` + full API |
| Data | Local **read projection** + local **write queue** (encrypted at rest) | **Authoritative MySQL ledger** |
| Auth holds | member credential hashes only (`role='member'`) | all users incl. staff + 2FA |
| Cross-node role | **passive** — only its local DB + a wg-only ingest endpoint | **active initiator** — runs the Sync Agent |
| Secrets | own `JWT_SECRET`, mTLS cert, projection-encryption key | own `JWT_SECRET`, mTLS cert, API key, ledger keys |

### Data flow
- **Reads:** member → Cloudflare → client member API → **local projection DB**. Fast; the core is never in the request path.
- **Writes:** member action → written locally as a **pending** request with a client-generated **UUID** → returned to the member as "processing".
- **Sync (core initiates over WireGuard, mTLS + HMAC + API key):**
  - **PULL:** core fetches the client's pending write queue, validates, applies to the authoritative ledger **idempotently** (UUID dedupe), records result.
  - **PUSH:** core pushes updated projections (balances, transactions, statuses, messages, notifications) back to the client.
- **Failure mode:** if the tunnel/core is down, the client still serves **reads from cache** and **accepts writes as pending**; nothing is lost, members see "processing".

### Why this is the secure choice
- Compromise of the public node yields **no inbound path** into the CU network and **no ability to move money** (it can only populate a queue the core chooses to drain).
- Public box carries **minimal, encrypted, member-only data** — no staff hashes, no high-sensitivity PII it doesn't need to display.

---

## 3. Security controls (layered)

| Layer | Control |
|---|---|
| Edge | Cloudflare WAF + DDoS + rate limiting; **Cloudflare Tunnel** (no public inbound port on origin) OR origin firewalled to CF IPs + Authenticated Origin Pulls (mTLS) |
| Transport (node↔node) | **WireGuard** (peer-pinned, wg-only firewall) + **mTLS** between services + **HMAC-signed** bodies (timestamp + nonce, replay window) + **API key** identifier |
| App auth | Per-node `JWT_SECRET`; member-mode middleware asserts `role='member'` on every request; access + refresh tokens; lockout + rate-limit on `/auth/login` |
| Data at rest | Projection + queue payloads encrypted on the public node; sensitive PII masked/tokenized; **no staff credential hashes** on public node |
| Integrity | All member writes idempotent (UUID); core is sole authority for ledger changes; full audit log of inter-node requests on the core |
| Ops | Separate secrets per node; scheduled key/cert rotation; least-privilege OS + DB users; monitoring/alerting; **2FA for staff**; backups of the **core** (public node is rebuildable) |

---

## 4. Data contracts

### 4.1 Authoritative tables (core) — new
- `member_messages` — member ↔ staff secure threads
- `payees` — member billers
- `bill_payments` — payments (debit member account → biller / own loan)
- `applications` — loan & service applications + review status
- `member_credentials` *(or reuse `users` where `role='member'`)* — portal logins
- `inter_node_audit` — every sync request (who/when/what/result)
- `outbound_projection_log` / change-tracking columns — to drive incremental push

### 4.2 Projection tables (client node) — read-only mirrors
`p_members`, `p_accounts`, `p_transactions`, `p_loans`, `p_payees`,
`p_messages`, `p_applications`, `p_notifications`, `p_member_credentials`
(hash only). Encrypted at rest; updated only by the Sync Agent.

### 4.3 Write queue (client node)
`pending_requests(id UUID PK, member_id, type, payload_encrypted, status[pending|sent|applied|failed], idempotency_key, created_at, applied_at, result_json)`
where `type ∈ {transfer, billpay, loan_apply, service_apply, message, profile_update}`.

### 4.4 Sync message envelope (node↔node)
```
{ api_key_id, ts, nonce, hmac, mtls(handshake),
  body: { kind: "push_projection" | "pull_queue" | "ack_results",
          cursor, items:[...], encrypted_fields:[...] } }
```

---

## 5. The "Core Gateway" abstraction (keeps app code clean)

The member API never talks to the core directly. It talks to a small interface:

```
CoreGateway:
  readProjection(entity, filter)        -> rows from local projection
  enqueueWrite(type, payload)           -> {id, status:'pending'}
  getWriteStatus(id)                    -> status/result
```

- **Dev mode:** `CoreGateway` is backed by the **local dev MySQL directly** (so we can build & test all features fast on one box).
- **Prod mode:** same interface, backed by the **projection DB + write queue**.

This means **Phases 1 & 5 (the actual features) can be built and tested now**,
and the distributed plumbing (Phase 3) slots in behind the interface without
rewriting feature code.

---

## 6. Member API surface (the contract for web + mobile)

Versioned, JSON, JWT. All endpoints are **member-scoped** (server derives
`member_id` from the token — never trusts a client-supplied id).

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/v1/auth/login` | member-only login (rejects staff roles) |
| POST | `/api/v1/auth/refresh` / `logout` / `change-password` | session lifecycle |
| GET | `/api/v1/me` | profile |
| GET | `/api/v1/accounts` / `/accounts/{id}` | own accounts |
| GET | `/api/v1/transactions` | own history (filters, paging) |
| GET | `/api/v1/loans` / `/loans/{id}` | own loans + schedule |
| POST | `/api/v1/transfers` | enqueue transfer (idempotent) |
| GET/POST/DELETE | `/api/v1/payees` | manage billers |
| POST | `/api/v1/billpay` | enqueue bill/loan payment |
| GET/POST | `/api/v1/messages` | read thread / send to admin |
| GET/POST | `/api/v1/applications` | list / submit loan or service application |
| GET | `/api/v1/notifications` (+ read-all) | already referenced by the UI |

Admin-side (core node only): message inbox + reply, application review,
member-login provisioning.

---

## 7. Build phases

> Each phase ends with a working, testable deliverable. Phases 1–2 + 5 build the
> product on a single box; Phases 3–4 make it the secure two-node system;
> Phase 6 adds mobile; Phase 7 hardens for launch.

### Phase 0 — Foundations
- `docs/ARCHITECTURE.md` + this plan (✅ this commit)
- Config: `APP_NODE_ROLE = member | admin`, per-node secrets in `.env`
- Define the `CoreGateway` interface

### Phase 1 — Member application layer (single-box, dev gateway) — ✅ MEMBER FEATURES BUILT
> Built on the existing platform (single box) against the live DB. Accounts /
> transactions / transfer / loan-apply already existed and are member-scoped.
> Added: secure **messaging** (member↔staff + admin inbox), **applications**
> (loan/service + admin review), and **bill pay** (payees + payments via the
> ledger). Still outstanding in this phase: the dedicated member login page +
> `force_password_change` flow, and admin **member-login provisioning** UI.

- Split login: **`portal/login.html`** (member-only) separate from staff login
- Member-only API route group (loads only on `role=member` deployments)
- Finish/verify **accounts**, **transactions**, **transfer**, **loans** pages
- New features: **messaging**, **bill pay (payees + pay)**, **apply (loan/service)**
- DB migrations for the new authoritative tables (platform migration convention)
- **Acceptance:** a `member` user logs in at `/portal/login.html`, sees only their data, and can use all 5 features end-to-end against the dev DB.

### Phase 2 — Projection + queue + gateway (prod data layer)
- Projection tables + write-queue schema on the "client" side
- Swap `CoreGateway` dev→prod implementation behind the same interface
- Idempotency + status surfacing in the UI ("pending → completed")
- **Acceptance:** member reads come from the projection; writes land in the queue with UUIDs and show pending status.

### Phase 3 — Sync Agent + secure inter-node channel
- Core-initiated agent: PULL queue, apply idempotently, PUSH projections + results
- WireGuard config; wg-only ingest endpoint on the client; mTLS; HMAC + nonce replay protection; API key; key/cert rotation
- `inter_node_audit` logging; reconciliation + conflict rules; backoff/retry
- **Acceptance:** a transfer submitted on the public node is applied on the core and the new balance is reflected back on the public node within the target window; killing the tunnel degrades gracefully (reads cached, writes queued).

### Phase 4 — Deployment modes & infra hardening
- `install.sh --role=member` (portal + member API + projection/queue) vs `--role=admin` (admin + full API + ledger + Sync Agent)
- Two nginx configs; member config hard-blocks `/admin/` + admin API prefixes
- Cloudflare Tunnel (or locked origin + Authenticated Origin Pulls) for the member node
- systemd units; secrets provisioning; backup/restore for the core
- **Acceptance:** two boxes, isolated; public box serves members only; admin box reachable only on the LAN/VPN.

### Phase 5 — Admin-side member servicing (core node)
- Staff **message inbox** + reply; **application review** (approve/decline + notes)
- **Member login provisioning** UI (create/enable login, temp password, force change, lock/unlock)
- **Acceptance:** staff can provision a member, answer messages, and decide applications; results sync to the member.

### Phase 6 — Mobile (Android + iOS)
- Make the portal a **responsive PWA** (manifest + service worker + installable, offline shell)
- Wrap with **Capacitor** (recommended over a raw WebView): native shell loading the portal, plus native plugins for **secure token storage** (Keychain/Keystore), **biometric unlock**, and **push notifications** (APNs/FCM)
- Mobile security: **TLS certificate pinning** to Cloudflare, refresh-token rotation, optional Play Integrity / App Attest, no secrets in the bundle
- API readiness: stable `/api/v1`, CORS allowlist for app origins (`capacitor://localhost`, `https://localhost`), push-notification fan-out wired to the messaging/notification flow
- **Acceptance:** an installable app on both platforms authenticates against the same member API, supports biometric login, and receives a push when admin replies or a payment posts.

### Phase 7 — Hardening & launch
- Pen-test checklist, rate-limit tuning, monitoring/alerting, log review
- Key/cert rotation runbook, incident runbook, backup restore drill
- Staff 2FA enforced; final data-minimization review of the public projection

---

## 8. Mobile strategy detail

- **One contract, many clients.** Web portal, Android, iOS all call the public
  member API via Cloudflare. No app talks to the core directly.
- **WebView vs native:** start with **Capacitor (hybrid WebView)** — fastest to
  ship, reuses 100% of the portal UI, but still gives native push, biometrics,
  and secure storage through plugins. A fully native rewrite can come later if
  needed; the API doesn't change.
- **Auth on mobile:** short-lived access token + rotating refresh token in
  secure storage; biometric gate to unlock; logout revokes refresh server-side.
- **Push:** "admin replied", "payment posted", "application decided" →
  notification record on core → pushed to client projection → fan-out via
  FCM/APNs.

---

## 9. Open items to confirm before/while building

1. **Member credentials:** ✅ DECIDED — reuse the existing `users` table with `role='member'` (the table already has 2FA, lockout, `force_password_change`, status). No parallel table. The public node's projection only carries `role='member'` rows, so staff hashes never land on the public box.
2. **Primary API:** ✅ DECIDED — member features are on **PHP `/api/v1`** (the live API the frontend already calls). The Python `/api/v2` service is left as-is.
3. **Sync transport:** poll interval / event-trigger target for write latency (e.g. ≤5s) and balance-freshness SLA.
4. **PII on public node:** which fields may the projection store vs must be masked/tokenized.
5. **Bill pay semantics:** internal payees settled by staff externally (recommended first version) vs an external payment rail later.

---

## 10. Top risks & mitigations

| Risk | Mitigation |
|---|---|
| Public node breach | No inbound to LAN; no money movement; minimal encrypted data; rebuildable |
| Double-posted payment on retry | Client UUID + core idempotency dedupe |
| Stale balances | Event-triggered push on every core transaction + periodic reconcile |
| Token theft on mobile | Secure storage, short access tokens, refresh rotation, cert pinning |
| Cloudflare bypass to origin | Cloudflare Tunnel or CF-IP firewall + Authenticated Origin Pulls |
| Key/cert compromise | Rotation schedule + revocation; secrets out of repo |

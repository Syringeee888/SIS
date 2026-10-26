# AGENTS.md — ReserVibe project memory

Durable context for any AI agent or developer working in this repo.
**Status:** updated 2026-10-23 — modules 1–10 have school-demo workflows; active application records use MySQL repositories. No online payment gateway is connected; Google Calendar requires OAuth credentials in the developer's local `.env` and an admin-triggered sync.
Update this file when scope or architecture changes.

---

## 1. Project identity

- **Name:** ReserVibe — a web-based reservation and booking management system.
- **Client/site:** Verde Royale Resort — Brgy. Ambonao, Calasiao, Pangasinan.
- **Nature:** Academic / school project. Optimize for **clarity and demonstrable good
  practice** (explainable in a defense) over enterprise-grade robustness. It is not a
  production deployment, but it does behave like a real system: real validation, real
  concurrency control, real HTTP semantics.

## 2. Tech stack

- **Backend:** PHP 8.2 (OOP) + PDO/MySQL repositories for accommodations, holds, reservations, payments, and login throttling.
- **Frontend:** HTML5, CSS3, vanilla JavaScript (Fetch API).
- **Calendar:** availability feed plus optional Google Calendar OAuth and manual reservation sync; credentials are not included.
- **Payments:** receipt images can be uploaded privately and reviewed by staff; ledger entries are offline demo records, with no payment gateway or refunds.
- **Staff access:** local session-backed accounts use `password_hash()` values stored only in ignored `.env`; five failures in ten minutes trigger a five-minute account-and-IP lockout.
- **Mail:** no email integration is implemented.

**Coding guidelines the project commits to:**

| Guideline | State |
|---|---|
| Parameterized queries only (PDO), never raw SQL concatenation | ✅ The MySQL collection store uses prepared statements. |
| Validate/sanitize all input | ✅ `App\Helpers\Validator`, applied in every POST endpoint |
| CSRF on every mutating endpoint | ✅ `App\Services\CsrfProtection`, enforced on all POSTs |
| Uploads: real MIME sniffing (`finfo`), size limits, randomized names | ✅ payment proofs, stored outside `public/` |
| Layering: Repositories / Services / API endpoint files / frontend | ✅ enforced — endpoints contain no business logic, services contain no storage access |

## 3. Scope — 10 core modules

| # | Module | State |
|---|---|---|
| 1 | Accommodation & Package Catalog | Implemented: searchable guest catalogue plus admin create/edit/hide; MySQL rates feed server quotes |
| 2 | Availability | Implemented: availability check and calendar event feed; no calendar UI |
| 3 | Reservation & Cost Calculation | Implemented: browser booking flow, server-side quote, reservation creation |
| 4 | Conflict Prevention & Temporary Holds | ✅ atomic, lazy expiry, race-tested |
| 5 | Payment Proof & Verification | Implemented: private image upload, staff review, approve/reject |
| 6 | Payment & Balance Settlement | Implemented: offline ledger, partial payments, amount-paid/balance calculations |
| 7 | Cancellation & Rescheduling | Implemented: guest self-service by reference/email; availability/price recalculated; 7-day reschedule cutoff and 3-day non-refundable deposit rule recorded |
| 8 | Google Calendar Integration | Optional: OAuth and manual sync; needs the owner's Google API credentials and consent |
| 9 | Walk-ins & Guest Status | Implemented: staff walk-in entry and arrived/left workflow |
| 10 | Admin Dashboard & Reports | Implemented: role-gated desk, KPIs, CSV export |

**Longer-horizon vision** (from the project's written spec — *not* current build scope):
role tiers (Admin / Front Desk / Cashier / Housekeeping), housekeeping task board, dynamic
pricing & promo codes, invoicing/refunds, reports & analytics, SMS+email automation, and a
post-stay feedback & review system with moderation and satisfaction analytics.

## 4. Architecture decisions already locked

| Area | Decision | Rationale |
|---|---|---|
| Routing | **Hybrid, resolved:** guest pages render through the front controller (`?action=`); every JSON endpoint is its own file under `public/api/` | One-file-per-endpoint is trivially explainable in a defense and needs no router library |
| Hold expiration | **Lazy check on read** — verified on `hold/status`, on `hold/create` and on booking confirmation; no cron job | No server-level cron on shared/school hosting |
| Views | **Plain PHP includes** — no templating engine | One less dependency |
| Database | Repositories use the supplied MySQL tables; `database/repository_columns.sql` adds app-specific status and metadata fields | Requires `pdo_mysql` and the supplemental SQL migration |
| Timezone | `Asia/Manila`, set in `src/Config/config.php` (overridable via `APP_TIMEZONE` in `.env`) | Hold countdowns must read in resort-local time, not hosting default |
| Money | Server-authoritative: the client sends **add-on codes and dates, never prices** | A tampered request cannot change the total |
| Payment methods | Declared once in `config.php` under `payment_methods`; the booking view renders them and the API validates `payment_method` against the same list | The page and the API can never disagree about what is offered, and `payment_method` is required (422 if unknown) |

### The concurrency design (the project's signature feature)

`HoldRepository` and `ReservationRepository` are storage-only PDO repositories. All
booking-critical operations run inside one MySQL advisory lock, acquired via
`DatabaseLock::withLock()`:

- `HoldManager::place()` — inside the lock it sweeps expired holds, reads reservations,
  runs the conflict check, and only then writes the new hold. There is no window between
  checking and writing.
- `ReservationService::create()` — takes the **same** lock, so confirming a booking cannot
  race a new hold either.

The lock is held on the same PDO connection used for database operations, so competing
requests on this app server serialize booking changes while the repositories read and write
MySQL rows.

**Limitation to state honestly:** the MySQL advisory lock coordinates processes connected to
the same MySQL server. Critical writes should still use database transactions and row locks
before multi-host production deployment.

**Evidence:** two simultaneous `hold/create` requests for the same fresh slot return
`201` and `409`, and exactly one hold is written. Re-runnable — see §8.

### Current implemented lifecycles

- Reservation: `pending_payment` → `pending_verification` → `confirmed`, or `cancelled`; proof rejection returns an active stay to `pending_payment`.
- Hold: `active` → `expired` | `released` | `confirmed`.
- Guest status: `reserved` → `arrived` → `left`; staff creates walk-ins directly.
- Payments are append-only ledger records; the prototype does not charge, refund or reverse money.

`ReservationRepository::BLOCKING_STATUSES` includes `pending_payment`, `pending_verification`,
and `confirmed`; cancellation removes a reservation from availability conflicts.

## Fix-list status

Completed from `ReserVibe-Fix-List.docx`: private password hashes plus failed-login lockout; maximum 30-night stays and bookings up to 365 days ahead; fixed 14:00/11:00 arrival times; visible reschedule/cancellation terms with server-side enforcement. README lists required PHP extensions; Unicode text has a fallback if `mbstring` is unavailable.

Still suggested for later: a printable confirmation view, guest-facing availability calendar, terms/privacy/house rules with booking acknowledgement, resort information, append-only staff action log, reservation search/filters, printable arrivals, and local room photos. A real or sample payment QR must remain clearly identified as a school demo and use owner-approved details.
## 5. Historical verification snapshot (2026-09-19)

The checks below were run on the repo state at that date. Files have changed since,
so these results are historical evidence only and do not verify the current working tree.

Commit `f4c23b7 "Initial commit"` plus uncommitted work. **All 36 PHP files pass `php -l`.**

```
public/
  index.php                     front controller: ?action=home|rooms|book
  .htaccess                     rewrites non-existent paths to index.php
  api/bootstrap.php             autoloader + config + session + JSON headers + error handler
  api/csrf-token.php            GET  — mint/return CSRF token
  api/catalog/list.php          GET  — Module 1: catalogue, add-ons, booking settings
  api/availability/check.php    GET  — Module 2: is this slot free?
  api/availability/calendar.php GET  — Module 2: FullCalendar event feed (raw array)
  api/reservation/calculate.php POST — Module 3: quote (side-effect free)
  api/reservation/create.php    POST — Modules 3+4: hold → reservation
  api/reservation/list.php      GET  — staff-only list
  api/hold/create.php           POST — Module 4: claim a slot (201) or refuse (409)
  api/hold/status.php           GET  — Module 4: countdown, lazy expiry
  api/hold/release.php          POST — Module 4: give the slot back
  assets/{css,js}               style.css, booking.js, calendar.js, dashboard.js, site.js
  uploads/payment-proofs/       empty, for Module 5
src/
  Config/config.php             ALL tunables: deposit_rate, hold_minutes, addons, payment_methods, uploads, paths, timezone
  Config/Database.php           lazy PDO singleton configured from DB_* values in .env
  Helpers/Config.php            dot-notation reader: Config::get('uploads.max_bytes')
  Helpers/Assets.php            versioned asset URLs: Assets::url('/assets/css/style.css') -> ?v=<mtime>
  Helpers/Validator.php         date/int/text/email/phone/in/list — null means invalid
  Helpers/Response.php          success()/error()/raw()/json()/render()/requireMethod()/requireRole()/input()
  Repositories/DatabaseLock.php       MySQL advisory lock for serialized mutations
  Repositories/AccommodationRepository.php
  Repositories/HoldRepository.php
  Repositories/ReservationRepository.php
  Services/CsrfProtection.php
  Services/CostCalculator.php         quote(), nightsBetween(), unknownAddonCodes()
  Services/AvailabilityService.php    pure overlap/conflict logic + calendar events
  Services/HoldManager.php            place/status/release/sweepExpired/markConfirmed
  Services/ReservationService.php     create from hold, list
  Controllers/, Models/               Accommodation model is now a facade over the repository
views/guest/                    home, catalog, book, 404
views/{admin,staff,layouts}/    still empty
```

**API contract:** success → `{"success": true, "data": ...}`; failure →
`{"success": false, "message": "...", "errors": {...}}`. Status codes used: 201 created,
403 role, 404 missing, 405 method, 409 conflict, 419 CSRF, 422 validation.

**End-to-end test results (php -S, re-runnable — see §8):**

Front-controller pages also verified: `?action=home`, `rooms`, `book&id=1|3` all 200, unknown room falls
through to the 404 view, `?action=bogus` is a real 404 that now renders the *styled* 404 page (it used to
return bare text), and the booking page renders its 4 options, both add-ons, its hold window and its 6
payment methods from config. Zero PHP warnings across every run.

| Check | Result |
|---|---|
| Catalog returns 4 accommodations + settings | 200 |
| Quote: 2 nights + videoke + grilling on the ₱1,500 cottage | subtotal 3000, add-ons 1100, total **4100**, deposit **2050** |
| Place hold | 201, `HOLD-0001`, expires in 5400s (90 min) |
| Second hold, overlapping dates | **409**, names `HOLD-0001` |
| Hold countdown | 200, `remaining_label: 89:58`, `usable: true` |
| POST with no CSRF token | 419 |
| Confirm booking from hold | 201, `RSV-0001`, pending_payment, deposit 1900 |
| Hold the same slot again | **409**, now names `RSV-0001` (reservation blocks, not just the hold) |
| Availability check / calendar feed | 200 / 200 with a red "reserved" event |
| Staff list without role / with `X-User-Role: staff` | 403 / 200 |
| Release hold → re-hold same slot | 200 released → 201 granted |
| **Two simultaneous holds, same fresh slot** | **201 + 409, exactly 1 hold written** |
| Stale hold planted → its slot re-booked | status flips to `expired` on read, re-booking returns 201 |
| Booking page renders the chooser | 200, 6 method cards, radios for gcash/maya/bank_transfer/paypal/card/cash |
| `catalog/list.php` exposes `payment_methods` | 200 |
| Booking via `payment_method: paypal` | 201, record stores `payment_method`, `payment_method_label`, `payment_requires_proof: false` |
| Booking via `payment_method: cash` | 201, `settles_on_arrival: true` |
| Booking with `payment_method: bitcoin` / omitted | **422** naming the valid codes |
| Cancel releases the hold, then the same slot re-books | 200 → 201 |
| Asset URLs carry `?v=<mtime>`, version changes on edit | `1789920255` → `1789920436` after touching style.css |
| Versioned `/assets/css/style.css?v=…` and `/assets/js/booking.js?v=…` | 200 |
| Unknown action `?action=bogus` | 404 **with the styled site layout** (was plain text) |
| Invalid room id `?action=book&id=999` | 404 styled page |
| PHP warnings/notices during the run | 0 |

## 6. Bug found and fixed: `getEnv()` was PHP's `getenv()`

The original `src/Config/config.php` defined a helper called `getEnv()` inside a
`function_exists('getEnv')` guard. **PHP function names are case-insensitive**, so
`getEnv()` *is* the built-in `getenv()`: the guard was always true, the definition was
silently skipped, and every lookup hit PHP's `getenv($name, bool $local_only = false)` —
whose second parameter is a bool, not a default. It returns `false` for a missing key.

Consequences that were live in this repo until 2026-09-19:

- All four `db` config values were `false` rather than `localhost` / `reservibe` / `root` / `''`.
- `date_default_timezone_set('')` raised "Timezone ID '' is invalid" and left the server on
  `Europe/Berlin`, so hold timestamps read `+02:00` for a Philippine resort.

Fixed by renaming the helper to `reservibe_env()` (with a comment explaining why), and it now
also falls back to real environment variables so hosting can override `.env`. **Do not
reintroduce a `getEnv()` helper** — it will collide again.

## 7. Discrepancy resolved: the inherited capsule

The inherited `CONTEXT_CAPSULE.md` describes a different, more advanced lineage
(`src/Repositories` + `src/Services` layers, a `/data/*.json` store, stubbed
`GoogleCalendarService`/`MailerService`, 20 endpoints) and references a
`reservibe-project.zip` **that does not exist anywhere on this machine**.

Resolution: this repo is the base. Its locked decisions and layering were adopted, and
Modules 1–4 were rebuilt here from scratch in that shape. The capsule remains accurate as
**design intent** (its endpoint list, lifecycles and JSON-store idea) and is a fair
reference for what Modules 5–10 should look like — but it never described files that exist.

Two conflicts from the capsule, now settled:

- **Routing** — hybrid: pages via the front controller, JSON via one file per endpoint (§4).
- **Hold duration** — `hold_minutes` in config = 90, and the booking view now *renders* that
  value instead of hardcoding "15 minutes".

## 8. How to run and verify

Run from the project root with XAMPP PHP `C:\xampp\php\php.exe -S 127.0.0.1:8010 -t public`, then follow
the browser and staff-desk walkthrough in `README.md`. Staff access comes from a
session created by the login form; role headers do not grant access. Mutating API
requests require the session's CSRF token. The README reset task is destructive and
clears holds, reservations, local payment records, uploaded proofs, and the Calendar
token; use it only when you intentionally want to discard the demo data.

The verification table in §5 is a dated snapshot of Modules 1–4 from 2026-09-19,
not evidence for the current working tree. Re-run current checks on a disposable
copy before relying on them; do not use the reset task against the project data.

## 9. Optional work beyond the 10 school-demo modules

The core repositories use MySQL tables directly. `DatabaseLock::withLock()` uses a MySQL
advisory lock to coordinate mutations. Production deployment would also need
strong account management, secret rotation, backups, a refund policy, and live payment
provider integration. SMTP/email notifications are not part of the 10-module scope.

## 10. Housekeeping / risks

- **⚠ Exposed credential:** the git remote URL in `.git/config` embeds a GitHub personal
  access token. Rotate/revoke it and switch to a credential helper or SSH. `.env` is
  correctly gitignored — keep it that way.
- Staff usernames and password hashes must be configured in private `.env`; no plaintext defaults are committed. The JSON login throttle suits this localhost school demo, not production account security.
- **PayPal and card are UI-level only.** No gateway is integrated: choosing them records
  the method on the booking and nothing is charged. Say that plainly rather than implying
  a working payment integration.
- "Cancel Booking" releases the hold, but navigating away with **Return to Menu** does not —
  the hold simply expires on its own. If that matters later, release it on `beforeunload`
  or expire it server-side faster.
- Staff API routes use `Response::requireRole([...])`, which checks the signed-in session.
  The old `X-User-Role` header is ignored.
- Main booking, catalog, payment, and login-throttle records now use MySQL tables directly.
- **⚠ Concurrent editor.** Parts of this repo have been written by something other than the
  assistant session that wrote most of this file. The `.booking-shell` rule and the
  `!important` note in `style.css`, plus the matching wrapper in `book.php`, appeared on
  2026-09-21 around 00:02 with no assistant write behind them, and one write was refused
  because a file had changed underneath. A Kilo Code worktree is registered at
  `.kilo/worktrees/quilled-swamp/` (detached HEAD at `1035b2c`). Re-read a file before
  editing it, and settle who owns the working tree before the next round of changes — if
  that worktree gets committed and merged, expect conflicts against uncommitted work here.
- **Asset caching.** The PHP dev server sends no cache headers, so browsers heuristically
  cache CSS/JS and keep serving a stale copy after an edit — this already caused one
  confusing "my fix did nothing" round trip. `App\Helpers\Assets::url()` appends
  `?v=<mtime>` to defeat it; use it for any new asset reference in a view.
- `composer.lock` is tracked but `.gitignore` lists it — still unresolved; pick one policy.
- Booking and payment records live in MySQL. Receipt uploads remain under
  `public/uploads/payment-proofs/` and should not be committed.

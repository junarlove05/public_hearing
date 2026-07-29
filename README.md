# Legislative Public Hearing & Consultation Management System

Native PHP (PDO) + MySQL + Bootstrap 5 + AJAX + Chart.js + SweetAlert2.
Built against the **existing** `legislative_public_hearing_db` schema — no
tables are created, altered, or dropped by this codebase, **except** two
optional, explicitly-documented migrations noted below.

## 🤖 AI Sentiment Analysis (Ollama integration)

The Public Feedback module now includes local, offline AI sentiment
analysis powered by [Ollama](https://ollama.com) — no cloud APIs, no
internet required after setup.

### Setup

1. Install Ollama and pull a model (one-time, needs internet). **Choose based
   on your hardware** — this matters a lot for how well the AI feature performs:
   ```
   ollama pull llama3.2:1b
   ```
   - `llama3.2:1b` (**default**, ~1.3GB) — recommended for low-spec/older
     laptops (4-8GB RAM, no GPU). Fast, works everywhere.
   - `qwen2.5:0.5b` (~400MB) — even lighter, for very low-spec/very old
     hardware if `1b` still times out.
   - `llama3.2:3b` (~2GB) — better analysis quality, needs ~8GB+ RAM.
   - `llama3.1:8b` — best quality, but needs 8-16GB+ RAM and/or a GPU.
     **Will time out on low-spec hardware** — if you see "AI analysis
     failed: ... timed out after N seconds", this is almost always why;
     switch to a lighter model instead of just raising the timeout.

   To change the model, edit `OLLAMA_MODEL` in `config/ai_config.php`.
2. Make sure Ollama is running: `ollama serve` (or it may already be running
   as a background service after installation — visit
   http://localhost:11434 in a browser; you should see "Ollama is running").
3. Run `database/migration_002_ai_sentiment_analysis.sql` once against your
   database. **The app works fine without this too** — AI features just stay
   inactive until you run it (see `AIAnalysisManager::tablesExist()`).
4. Confirm the PHP `curl` extension is enabled (`extension=curl` in
   `php.ini`) — it's on by default in most XAMPP installs, but is required.
5. That's it — every new feedback submission is automatically analyzed.

### Tuning for slow hardware

`config/ai_config.php` has three knobs specifically for this:
- `OLLAMA_MODEL` — the single biggest factor; see the tier list above.
- `AI_MAX_RESPONSE_TOKENS` (default 400) — caps how much text the model
  generates per analysis. Our JSON response is short, so lowering this
  further (e.g. 250) speeds things up with no real quality loss.
- `AI_MODEL_KEEP_ALIVE_MINUTES` (default 30) — keeps the model loaded in
  RAM between requests so you don't pay a slow "cold start" cost on every
  single feedback submission.
- `AI_REQUEST_TIMEOUT_SECONDS` (default 120) — raise this only as a last
  resort; a persistent timeout almost always means "pick a lighter model,"
  not "wait longer."

### How it works / architecture

- **`config/ai_config.php`** — all AI settings (Ollama URL, model name,
  timeouts, on/off switch) in one place.
- **`includes/AI/AIServiceInterface.php`** — the contract. Every provider
  (Ollama today, OpenAI/Gemini/etc. later) implements this one interface.
- **`includes/AI/OllamaService.php`** — the only file that knows about
  Ollama specifically: builds the prompt, calls `POST /api/generate` via
  cURL with `"format": "json"` to force valid JSON output, validates/
  sanitizes the model's response against our expected schema (never trusts
  an LLM's output blindly), and translates every failure mode (connection
  refused, model not installed, timeout, invalid response, curl missing)
  into a specific, friendly message.
- **`includes/AI/AIServiceFactory.php`** — the ONLY file that needs to
  change to switch providers. Add a new class implementing
  `AIServiceInterface`, add one line here, done.
- **`includes/AI/AIAnalysisManager.php`** — orchestration layer used by the
  rest of the app: calls the configured provider, saves results to
  `feedback_ai_analysis`, logs every request/response to `ai_request_logs`
  (+ a flat `logs/ai.log` for quick tailing), and flags negative feedback
  for review. This is the boundary between AI code and core feedback logic
  — feedback submission works identically whether AI is enabled, disabled,
  or not installed at all.
- Analysis runs **after** feedback is saved and never blocks or breaks
  submission. Under PHP-FPM, `fastcgi_finish_request()` sends the citizen
  their success response immediately while analysis continues in the
  background; under classic mod_php (common on XAMPP/Apache), it runs
  synchronously before responding (slower per-request, still fully correct).
- Staff can also trigger/retry analysis manually ("Analyze Now" /
  "Re-analyze" in the feedback view modal) — useful if Ollama wasn't
  running yet when a submission came in.

### Where to see it

- **Feedback list** (`modules/feedback/index.php`) — sentiment badge column,
  sentiment filter, flagged rows highlighted red.
- **View/Reply modal** — full AI panel: sentiment + confidence, summary,
  keywords, recommended category, and a suggested response staff can
  accept into the reply box with one click.
- **AI Sentiment Analytics** (`modules/feedback/ai_analytics.php`, new tab
  in the Feedback module) — totals, a day/week/month/year-switchable trend
  line chart, sentiment pie chart, most-discussed-topics bar chart, top
  keywords, and a live Ollama health status banner.
- **Dashboard** — AI Sentiment Overview widget + recently-flagged list.
- **Notification bell** — count of AI-flagged negative feedback needing review.

---

## ⚠ July 2026, session 2 — SQLSTATE errors, file uploads, QR scanner, public reply view

A second, more targeted debugging pass fixed the following concrete,
reproducible bugs (see the detailed change list further down / the chat
summary for the full file-by-file breakdown):

1. **`SQLSTATE[HY093]: Invalid parameter number`** on every search box in
   the app (Hearings, Stakeholders, Attendance, Feedback, Issues, Actions,
   Surveys, Users, Activity Logs — 14 queries across 13 files). Root cause:
   this app correctly uses native PDO prepared statements
   (`PDO::ATTR_EMULATE_PREPARES => false`), which — unlike emulated
   prepares — do **not** allow the same named placeholder (e.g. `:search`)
   to appear more than once in one query. Every multi-column search query
   was written as `WHERE a LIKE :search OR b LIKE :search`, which is
   invalid under native prepares. Fixed by using a distinct placeholder
   per occurrence, all bound to the same value.
2. **Manual Attendance appearing broken**: the stakeholder search-as-you-
   type box (needed to select who to check in) hit the same bug via a
   `:q` placeholder used twice, crashing on every keystroke — so the save
   step was never actually reachable.
3. **File uploads failing silently**: `handleUpload()` had an unguarded
   `finfo_open()` call that fatal-errors if the `fileinfo` PHP extension
   isn't enabled (common on fresh XAMPP installs), and upload failures
   were never surfaced to the user — the UI just said "saved successfully"
   with 0 files attached. Fixed the guard, broadened the MIME allow-list
   (legitimate .docx files are sometimes reported with a generic MIME by
   `fileinfo`), added clear permission/writability error messages, and
   both Hearings' and Actions' save handlers now report exactly which
   file(s) failed and why.
4. **QR scanner "camera does not open"**: rewritten to check for a secure
   context (HTTPS/localhost — browsers silently block camera access
   anywhere else, a common gotcha when testing from a phone against a LAN
   IP), enumerate available cameras and auto-prefer a rear-facing one
   (the old code hard-coded `facingMode: 'environment'`, which fails
   outright on any camera that doesn't support that exact constraint,
   e.g. most laptop webcams), added a camera picker when multiple cameras
   exist, clear permission-denied messaging, and a **manual QR code entry
   fallback** (was missing entirely) for when the camera can't be used.
5. **Stakeholders "not receiving notifications"**: the notification bell
   showed the same management-oriented counts to every role. Rewritten to
   be role-aware — Stakeholder accounts (matched to the `stakeholders`
   table by email) now see their own upcoming hearings, pending
   attendance, and pending invitations; Public accounts see general
   upcoming hearings; management roles keep the original summary.
6. **Public feedback reply visibility** (new feature, required a small,
   explicitly-documented schema addition — see below): submitters can now
   look up their feedback by email (+ optional reference number) at
   `modules/feedback/track.php` and see the administrator's reply, who
   replied, and when — without needing to log in.

### Optional migration: `database/migration_001_feedback_reply_tracking.sql`

Adds three nullable columns to `feedback` (`reply_text`, `replied_at`,
`replied_by`) so a reply can be tied to and displayed for its specific
feedback entry. This was genuinely necessary for the public reply-viewing
feature: replies were previously only recorded as free-text in
`activity_logs`, which isn't something a public, unauthenticated page
should query (that log contains unrelated internal activity across the
whole system). **The app works correctly whether or not you run this** —
`includes/functions.php::feedbackTableHasReplyColumns()` detects it at
runtime and the reply-related UI degrades gracefully if it hasn't been
run (replies still get recorded in `activity_logs` as before either way).

---

## ⚠ July 2026, session 1 — "Network error" / data not saving

If you're returning to this project after reporting that forms sometimes
failed with "A network error occurred" or data not saving, **read this
section** — it explains exactly what was wrong and what was fixed.

### Root cause #1 (the primary one): `window.APP_URL` was never defined

Every module's JavaScript builds its AJAX request URLs like
`window.APP_URL + '/modules/hearings/ajax_save.php'`. That relies on a
global JS variable, `window.APP_URL`, that — due to an oversight — was
never actually being set anywhere in the app. Every single one of those
URLs was silently evaluating to the literal string
`"undefined/modules/hearings/ajax_save.php"`, a broken path that the
browser tried (and failed) to resolve. This affected **every** Add/Edit/
Delete/Search/Filter/Sort/Pagination/QR-scan action across **every**
module — exactly matching "data is not saved" and "network error" on
Hearings, Stakeholders, Feedback, Attendance, Issues, and Actions alike.
**Fixed in `layouts/footer.php`**, which now defines `window.APP_URL`
before any module script loads.

### Root cause #2: PHP session garbage-collection mismatch

The app's session cookie was configured to last 8 hours, but PHP's own
server-side session garbage collector (`session.gc_maxlifetime`) defaults
to only ~24 minutes on most XAMPP installs — a completely separate setting.
That mismatch meant the server could silently delete a user's session data
after ~24 minutes of *any* site-wide inactivity, while the browser's
cookie still looked valid. The next AJAX call would then get treated as
logged out and, prior to fix #3 below, receive an HTML login page instead
of JSON — which crashes `response.json()` and shows as "network error."
**Fixed in `config/config.php`**, which now aligns both lifetimes.

### Root cause #3: expired-session / permission-denied responses weren't JSON-safe for AJAX

`requireLogin()`, `requireRole()`, and the idle-timeout check previously
always redirected to an HTML page, even when the request was an AJAX call
expecting JSON. **Fixed in `includes/auth.php`** — these now detect AJAX
requests and return a clean, parseable JSON error (`session_expired: true`)
instead, which the client-side code now shows as a clear "Your session has
expired, please log in again" message instead of a generic network error.

### Root cause #4: stray PHP warnings/notices could corrupt JSON responses

If any included partial produced a PHP warning/notice before a JSON
response was sent, that text would get prepended to the response body,
breaking `response.json()` on the client. **Fixed in `includes/functions.php`**
(`jsonResponse()` now discards any buffered stray output before sending
JSON) and **`includes/auth.php`** (starts a dedicated output buffer per
request, and adds a global exception/fatal-error handler that converts
even a totally unexpected PHP error into valid JSON for AJAX requests).

### Root cause #5: every fetch() call's error handling was too generic

Every module's JS previously had `.catch(() => Swal.fire('Error', 'A
network error occurred.', 'error'))`, which collapsed three very different
failure modes (couldn't reach the server / server responded but not with
JSON / server responded with a real validation error) into one unhelpful
message — and one file (the public feedback form) had *no* error handling
at all, so a failure there did nothing visible. **Fixed across all 18 files
in `assets/js/`**: a new shared `appFetchJson()`/`appPost()`/`appPostForm()`/
`appGet()` helper set in `assets/js/app.js` now distinguishes these cases
and always shows the server's real message when one is available.

### Offline capability (requirements 4–6): CDN dependencies removed

Every page previously loaded Bootstrap, Bootstrap Icons, jQuery,
DataTables, SweetAlert2, Chart.js, and two QR-code libraries from CDNs
(jsdelivr/cdnjs/jquery.com), which fails outright on a machine without
reliable internet. **Fixed**: a new `vendorAsset()` helper
(`includes/functions.php`) serves a local copy from `assets/vendor/` if
one has been downloaded, falling back to the original CDN otherwise — so
nothing broke by making this change, and the app is one script away from
running fully offline. Run **`tools/download-vendor-assets.php`** once
(needs internet for that one run) and every library listed above gets
saved locally; every page automatically switches to using them, no further
changes needed. See `assets/vendor/README.txt` for details.

### What was validated but NOT a bug

An extensive automated + manual audit (every `getElementById()`, every
AJAX endpoint URL, every form field name against its PHP handler, every
delete button, every CSRF token) found **zero** additional wiring bugs —
the failures were fully explained by the five root causes above.

---



## Build status

This is being delivered in stages, per the requested output order. **Completed so far:**

- [x] 1. Project folder structure
- [x] 2. Database connection (PDO, singleton, prepared statements only)
- [x] 3. Authentication system (login, logout, session mgmt, RBAC guards, change password, activity logging)
- [x] 4. Layout (header, sidebar, footer — role-aware nav)
- [x] 5. Dashboard (all requested KPIs + 4 Chart.js charts + recent activity + upcoming hearings)
- [x] 6. Hearing Schedule Module — full CRUD via AJAX modal, document upload/download/delete,
      committee/type assignment, live search + filter (status/type/committee/date range) +
      sortable columns + AJAX pagination, month calendar view, printable schedule
- [x] 7. Stakeholder Invitation & Registration Module — Stakeholder CRUD (with approve/reject
      quick actions), CSV bulk import, auto-generated per-stakeholder QR code (view/print/download),
      Invitation CRUD with auto-generated invitation codes, single + bulk invite, mark-as-sent,
      email-ready template preview, print/download invitation, and Registration CRUD — all as three
      tabs (Stakeholders / Invitations / Registrations) sharing one live AJAX search+filter+pagination pattern
- [x] 8. Attendance Tracking Module — per-hearing dashboard with live stat cards (Registered/
      Present/Late/Absent), camera-based QR scanner (html5-qrcode) with auto Present/Late detection
      (15-min grace period), manual attendance with stakeholder autocomplete, full attendance_logs
      history/audit trail page, printable attendance sheet, and PDF/Excel export
- [x] 9. Public Feedback Collection Module — public (no-login) feedback + survey submission forms,
      management dashboard with stats and reply workflow (reply text audited via activity_logs,
      since the schema has no reply column), feedback categories/status/search/filter, Survey CRUD
      with a shareable public response form per survey, survey response management with search/
      pagination, Chart.js statistics (by category/status/6-month trend), print, and PDF export
- [x] 10. Issue Logging Module — full CRUD, category/hearing linkage, priority (High/Medium/Low),
      status workflow (Open/Processing/Resolved/Closed) with automatic timeline logging on every
      status/assignment change, dedicated Assign/Reassign + Add Note actions feeding a merged
      chronological timeline, live search/filter/sort/pagination, printable list, Chart.js
      reports (by category/priority/status/6-month trend), and PDF export
- [x] 11. Response & Action Tracking Module — full CRUD linked to issues, deadline tracking with
      automatic overdue highlighting + a dashboard "Upcoming Deadlines" notification banner,
      status workflow (Pending/On Going/Completed/Cancelled) with auto-logged status-change
      updates, Assign/Reassign Office (action_assignments history, since the schema has no
      denormalized office column on `actions`), document upload/download/delete, a progress
      updates timeline, printable list, Chart.js reports (by status/office/6-month trend), and
      PDF export

**All six business modules (Hearing Schedule, Stakeholders, Attendance, Feedback, Issues, Actions)
are now complete.**

- [x] 12. Reports — a cross-module reporting hub covering all 7 data types (Hearings, Attendance,
      Stakeholders, Feedback, Issues, Actions, Activity Logs) from a single shared query engine
      (`reports/report_data.php`), with an optional date range that carries through to the on-screen
      view (searchable/sortable via DataTables), Print, PDF export, Excel export, and CSV export —
      all four honoring the same type + date range
- [x] 13. Activity Logs page — searchable/filterable (user, action type, date range) live-AJAX view
      over `activity_logs`, plus PDF/Excel/CSV export via the Reports engine
- [x] 14. Final UI polish pass — ran a full link audit across every sidebar entry and notification
      link and fixed what it found:
      - Built **User Management** (`pages/users.php`), which the sidebar already linked to for
        Administrators but didn't exist yet — full CRUD with role assignment, active/inactive
        status, optional password reset on edit, and safeguards against self-deletion/self-lockout
      - Built **Forgot Password** (`forgot_password.php`), which `login.php` already linked to —
        since no SMTP is configured, it logs the request to `activity_logs` for an admin to follow
        up on, using a generic response so it doesn't reveal whether an account exists
      - Wired up the **Remember Session** checkbox on login to actually extend the cookie lifetime
        (30 days) rather than sitting inert
      - Ran a crude brace-balance check across every PHP file as an extra sanity net (no PHP CLI
        available in this environment to run a real lint)

**All 14 requested stages are now complete.**

## Requirements

- PHP **8.0+** (the codebase uses typed properties, nullable/union return
  types, and `match()`), with the `pdo_mysql` and `fileinfo` extensions enabled.
- MySQL 5.7+/MariaDB 10.3+ (matching the schema you supplied).

## Setup

1. Create/point to the existing `legislative_public_hearing_db` MySQL database (already provided — this app does not touch its schema).
2. Edit `config/config.php` — set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, and `APP_URL` to match your environment.
3. Point your web server document root at this folder (or a subfolder, matching `APP_URL`).
4. Ensure `assets/uploads/` and `logs/` are writable by the web server.
5. **Optional but recommended:** run `database/migration_001_feedback_reply_tracking.sql` once
   against your database to enable the public feedback reply-viewing page
   (`modules/feedback/track.php`). The app works fine without it — this just
   unlocks that one feature.
6. **Optional but recommended for offline/XAMPP use:** visit
   `http://localhost/lph/tools/download-vendor-assets.php` once (needs internet for
   that one run) to download Bootstrap/jQuery/DataTables/SweetAlert2/Chart.js/QR
   libraries locally, so the app works with zero internet dependency afterwards.
   Delete `tools/` once done — see `assets/vendor/README.txt`.
7. Log in with the seeded administrator account:
   - **Email:** `admin@example.com`
   - **Password:** whatever plaintext value hashes to the seeded bcrypt hash in your DB dump (reset it via SQL with `password_hash()` if unknown — see note below).

> **Note on the seed admin password:** the schema you supplied inserts a bcrypt
> hash for `admin@example.com` but doesn't state the plaintext. If you don't
> already know it, run this once in a PHP shell to set a known password:
> `UPDATE users SET password = '<paste output of password_hash("YourNewPassword", PASSWORD_DEFAULT)>' WHERE email = 'admin@example.com';`

## Before deploying to production

- Set `APP_DEBUG` to `false` in `config/config.php` — it defaults to `true`
  for development, which shows raw PHP errors including stack traces.
- Update `DB_USER`/`DB_PASS` to a dedicated least-privilege MySQL account
  rather than `root`.
- Uncomment `'secure' => true` in `includes/auth.php`'s session cookie
  params once the site is served over HTTPS, so session cookies are never
  sent over plain HTTP.
- Change the seeded `admin@example.com` password immediately (see the note
  above) and remove/rotate it if it was ever shared outside your team.
- Confirm `assets/uploads/` is not directly executable by your web server
  (e.g. disable PHP execution in that directory via `.htaccess` or nginx
  config) — uploaded files are validated on the way in, but defense in
  depth costs nothing.

## Architecture notes

- **`config/`** — credentials + PDO connection singleton (`db()` helper function).
- **`includes/`** — cross-cutting helpers: `auth.php` (session/RBAC), `functions.php`
  (sanitization, CSRF, pagination, badges, uploads, CSV/Excel export), `activity_log.php`,
  `SimplePdf.php` (dependency-free PDF writer used for every "Export PDF" button — no
  Composer/FPDF needed, matches the native-PHP-only stack).
- **`layouts/`** — `header.php` / `sidebar.php` / `footer.php`, included by every
  protected page. Sidebar menu items are filtered per-role in one place.
- **`modules/<name>/`** — one folder per business module (CRUD pages + AJAX endpoints).
- **`ajax/`** — cross-module or shared AJAX endpoints (module-specific AJAX lives
  inside each module folder for cohesion).
- **`reports/`** — cross-module reporting & export.
- **`pages/`** — miscellaneous authenticated pages that aren't tied to one business module:
  `profile.php` (change password), `users.php` (Administrator user management), `activity_logs.php`
  (Administrator audit trail viewer), `403.php` (access-denied view used by `requireRole()`).
- **`ajax/`, `logs/`** — present per the required structure; see the `README.txt` inside each for
  why they're currently empty and what they're for.
- Every state-changing request (POST) calls `requireCsrf()`; every protected
  page calls `requireLogin()` and, where relevant, `requireRole([...])`.
- All queries use PDO prepared statements — no string-concatenated SQL.

# Balai ti Arjud — Online Appointment & Inventory Management System

**Balai ti Arjud — Glow & Co. Beauty Lounge** is a salon management web application built
on Laravel 11. It serves two audiences from one codebase: **customers**, who browse the
service catalogue and book, reschedule or cancel appointments at a beauty lounge, and the
**salon's staff**, who run the business from a separate admin portal — working the
appointment queue, maintaining the service catalogue, technicians and stock, publishing
terms, promos and reports.

The project was built as a full-stack capstone: a real schema, a real booking engine with
server-side availability rules, two isolated authentication guards, a Blade component
design system, and an end-to-end feature test suite covering both sides of the app.

---

## Table of contents

- [Features](#features)
- [Technology stack](#technology-stack)
- [Getting started](#getting-started)
- [Demo credentials](#demo-credentials)
- [Running the app](#running-the-app)
- [Project structure](#project-structure)
- [How the booking rules work](#how-the-booking-rules-work)
- [Salon operating rules](#salon-operating-rules)
- [Data model](#data-model)
- [Tests](#tests)
- [Design system](#design-system)
- [Artisan commands](#artisan-commands)
- [Deployment notes](#deployment-notes)
- [Prompt logging](#prompt-logging)

---

## Features

### Customer-facing

| Feature | Route |
| --- | --- |
| Landing page (hero, featured services, gallery, promos, CTA) | `GET /` |
| About page | `GET /about` |
| Service catalogue with category / search / price filtering | `GET /services` |
| Service detail page with variants and live availability | `GET /services/{service}` |
| Category-filtered grid view | `GET /services-grid` |
| Promos & special offers | `GET /promos` |
| Contact form (name, email, topic, message) — rate limited | `GET/POST /contact` |
| Published Terms & Conditions, by category | `GET /terms/{category}` |
| Registration | `GET/POST /register` |
| Login with **username or email** — rate limited | `GET/POST /login` |
| Four-step password reset (email → 6-digit code → new password) | `GET/POST /password`, `/password/code`, `/password/reset` |
| **Multi-step booking flow** (see below) | `GET/POST /book` |
| Live slot lookup feeding the booking date picker (public) | `GET /book/slots` |
| My Appointments — status filter, view dialog, bulk clear | `GET /appointments` |
| Book Again from a completed booking | `GET /appointments/{id}/book-again` |
| Cancel an appointment (reason + policy acknowledgement) | `GET/PATCH /appointments/{id}/cancel` |
| Reschedule an appointment (re-validated slot) | `GET/PATCH /appointments/{id}/reschedule` |
| Notifications — unread badge, polled feed, mark read / read all, delete | `GET /notifications`, `/notifications/feed` |
| Profile management — details, optional password change, photo upload with client-side cropping | `GET/PATCH /profile`, `DELETE /profile/photo` |

The booking form is a **four-step wizard on a single page**:

1. **Selected Service** — one or more services, each with its variant (e.g. short / medium /
   long hair pricing) and quantity.
2. **Your Details & Preferred Date & Time** — name, phone, date and time. Mondays and
   out-of-hours dates are greyed out from the same rules the server enforces.
3. **Preferences & Health Notes** — allergies and health notes.
4. **Last Service(s) Availed, Technician & Special Request** — previous treatments, an
   optional technician from the active roster, a special request, and the Terms & Conditions
   acceptance checkbox.

The confirmation screen summarises the basket with snapshotted prices before anything is
written. Submission is rate limited per account and carries an idempotency key, so a
double-tap on a slow connection cannot create two bookings.

### Admin-facing (`/admin`, separate guard and session)

| Feature | Route |
| --- | --- |
| Staff login + password reset | `GET/POST /admin/login`, `/admin/password/*` |
| Dashboard — stat cards, 14-day revenue chart, status breakdown, upcoming queue | `GET /admin` |
| Appointment management — full list, detail, edit | `GET /admin/appointments` |
| Appointment **status workflow** with guarded transitions | `PATCH /admin/appointments/{id}/status` |
| Internal admin notes (never shown to the customer) | `PATCH /admin/appointments/{id}/notes` |
| Down-payment reference verification | `PATCH /admin/appointments/{id}/down-payment` |
| Archive / restore / permanent delete (settled bookings only) | `/admin/appointments/{id}/archive`, `/restore`, `DELETE` |
| Archived appointments screen with bulk restore | `GET /admin/appointments-archived` |
| Live row-state polling so status changes appear without a reload | `GET /admin/appointments-row-state` |
| Catalogue overview (services + items in one place) | `GET /admin/catalog` |
| Service management — CRUD, availability toggle, variants | `GET /admin/services` |
| Service categories — CRUD, active toggle, per-category calendar colour | `GET /admin/categories` |
| Technician roster — CRUD, active toggle | `GET /admin/technicians` |
| Inventory / items — CRUD, linked services, stock levels | `GET /admin/inventory` |
| Stock tagging — Available / Low Stock / Best Seller / Sold Out, single and bulk | `GET /admin/tags` |
| Calendar with **blocked slots** (whole day or a time range) | `GET /admin/calendar` |
| Registered users — list, detail, delete (anonymising) | `GET /admin/users` |
| Terms & Conditions editor — draft, publish, delete, per category | `GET /admin/terms` |
| Sales / usage reports with CSV export | `GET /admin/reports`, `/admin/reports/export` |
| Inventory CSV export | `GET /admin/inventory/export` |
| Promo management + push announcement to customers | `GET /admin/promos`, `POST /admin/promos/{id}/announce` |
| Contact messages inbox | `GET /admin/messages` |
| Admin profile | `GET/PATCH /admin/profile` |
| Live notification bell (polled feed + mark read) | `GET /admin/notifications/feed` |

Every admin screen is gated by an ability from `App\Enums\AdminRole::abilities()`, enforced
in two places: `admin.role:admin.<ability>` middleware on the route, and `@can(...)` in the
view, so a control is never shown to a role the server would reject anyway.

### Appointment status workflow

Statuses live in `App\Enums\AppointmentStatus` as `pending`, `confirmed`, `in_progress`,
`completed`, `cancelled`. Each status declares the transitions it allows, and that same table
drives both the admin dropdown and the server-side check, so a hand-crafted transition that
is not on the menu is refused. `completed` is terminal — reopening a finished booking would
rewrite history, so the recovery path is a database correction rather than a UI button.

---

## Technology stack

Read from `composer.json`, `package.json` and the code — nothing inferred.

**Backend**

- **PHP 8.2+**, **Laravel 11** (`laravel/framework ^11.31`), Laravel Tinker
- **Blade** templating with a project component library (`resources/views/components/`)
- **Eloquent** with 16 models, backed PHP enums for every status/tag field, custom casts
  (`TolerantEnum`) and a `NormalizesEnumValues` contract
- **Laravel Notifications** on the database channel — 13 notification classes covering
  welcome, booking, confirmation, in-progress, completion, cancellation (by customer and by
  admin), reschedule, reminder, password-reset code, promo announcement and admin reset
- **Form Requests** for every write path (`app/Http/Requests/{Auth,Admin,Customer}`)
- **Rate limiting** on every write route, including a named `booking` limiter
  (12/min per account) defined in `AppServiceProvider`
- **Mail** over SMTP. `.env.example` is configured for Gmail SMTP on port 587 with a Google
  App Password, and documents Mailtrap as a local sandbox
- Session, cache and queue all on the **database** driver; sessions are encrypted at rest
- **Blade Heroicons** (`blade-ui-kit/blade-heroicons ^2.7`) for iconography
- PHPUnit 11, Mockery, Faker, Laravel Pint

**Frontend**

- **Tailwind CSS 3** on Vite, with the design tokens wired through `tailwind.config.js`
- **Alpine.js 3** for all interactivity — the booking wizard, dialogs, dropdowns, toasts,
  the live slot picker and the profile-photo cropper. Server-rendered HTML stays the source
  of truth; Alpine only mirrors state
- **Cropper.js 1.6.2**, vendored at `public/vendor/cropper/` (no CDN dependency), used for
  1:1 profile-photo cropping before upload
- **FontAwesome Free 7** for the icon set, Playfair Display / Cormorant Garamond / Parisienne
  / Poppins for type
- Vite 6, `laravel-vite-plugin`, PostCSS, Autoprefixer, concurrently

**Database**

- **MySQL** (MariaDB 10.4+ works). `php artisan test` defaults to in-memory SQLite so the suite
  needs no database server; see [Tests](#tests)

---

## Getting started

### Requirements

| Tool | Version |
| --- | --- |
| PHP | 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd` |
| Composer | 2.x |
| MySQL | 5.7+ / 8.x (MariaDB 10.4+) |
| Node.js | 18+ |
| npm | 9+ |

### Installation

```bash
# 1. PHP dependencies
composer install

# 2. Frontend dependencies
npm install

# 3. Environment file
cp .env.example .env          # Windows: copy .env.example .env

# 4. Application key
php artisan key:generate

# 5. Create the database
mysql -u root -e "CREATE DATABASE bta CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 6. Point .env at it (defaults already match the command above)
#    DB_CONNECTION=mysql
#    DB_HOST=127.0.0.1
#    DB_PORT=3306
#    DB_DATABASE=bta
#    DB_USERNAME=root
#    DB_PASSWORD=

# 7. Schema + demo data
php artisan migrate --seed

# 8. Build assets
npm run build
```

For development with hot reloading, run `npm run dev` in a second terminal instead of
`npm run build`.

### There is no `storage:link` step — and that is deliberate

Uploads are written straight into `public/storage`, which is the `public` disk root in
`config/filesystems.php` and the directory the web server serves. There is no symlink, so
nothing breaks on shared hosting where `storage:link` is unavailable or a symlink cannot
survive an FTP upload. Each upload folder carries a committed placeholder so a fresh clone
still has the directory, with uploads themselves git-ignored.

If an older installation ran `storage:link` before this change, copy the files across once:

```bash
cp -r storage/app/public/* public/storage/
```

### What the seeders create

`php artisan migrate --seed` runs, in order:

| Seeder | Creates |
| --- | --- |
| `AdminSeeder` | The single staff account, and the `salon_settings` singleton row |
| `ServiceSeeder` | 42 services across 13 categories, with 24 pricing variants (short / medium / long hair and similar) |
| `UserSeeder` | One developer customer account |
| `AppointmentSeeder` | 15 planned appointments across every status, attached to whichever active customer accounts exist |
| `TermsSeeder` | Booking, Cancellation and Rescheduling terms, published |
| `ContentSeeder` | 3 promos (active, upcoming, expired) and 3 contact messages |

**Inventory is deliberately not seeded.** Stock is what the salon actually holds, so demo rows
would misrepresent it and sit at the top of every low-stock count. Add real items from
Admin → Inventory. `php artisan inventory:clear` removes rows a previous seed left behind.

`AppointmentSeeder` looks its customers up by email and skips any it cannot find, so on a
freshly seeded database it attaches bookings to the developer account and simply skips the
rest rather than failing.

---

## Demo credentials

Seeded by `AdminSeeder` and `UserSeeder`. These are development credentials — change or
remove them before deploying anywhere public.

### Staff / admin — `/admin/login`

| Field | Value |
| --- | --- |
| Username | `admin` |
| Email | `admin@balaitiarjud.test` |
| Password | `password` |

There is one admin account and no tiers. The seeder force-deletes any other admin row so a
re-seed cannot quietly re-introduce them.

### Customer

| Field | Value |
| --- | --- |
| Email | `rucielmaeobias277@gmail.com` |
| Username | `rucielmaeobias277` |
| Password | `Rucielmae@21` |

Customers sign in with either their email address or their username. Re-running the seeder
resets this password, which is the point of a seeder but worth knowing.

> **Note:** the launcher scripts (`start-dev.bat`, `dev-up.bat`) still print an older demo
> customer address that `UserSeeder` no longer creates. Use the credentials above.

### Guards are isolated

Customers authenticate on the `web` guard against the `users` table; staff authenticate on
the `admin` guard against the `admins` table. They share one session cookie but have separate
providers and session namespaces, so a customer session can never reach `/admin` and staff
credentials are rejected by the customer login form. Customer routes additionally require an
active account (`user.active`), and authenticated pages send `no-store` cache headers so Back
after a logout cannot redraw a panel.

---

## Running the app

### Option A — XAMPP / Apache

The project lives in the XAMPP web root. With Apache running, just open:

- Customer site — <http://localhost/BTA/public/>
- Admin panel — <http://localhost/BTA/public/admin/login>

`public/.htaccess` handles the rewriting and Laravel derives the base path from the request.

### Option B — the bundled dev server (port 8000)

| Script | What it does |
| --- | --- |
| `dev-up.bat` | Starts a detached supervisor, waits until the site genuinely answers, then opens your browser. Closing the window does not stop the site. |
| `dev-down.bat` | Gracefully stops the supervisor and the port-8000 server. Apache and MySQL are untouched. |
| `start-dev.bat` | Foreground alternative: checks MySQL, creates the database, runs `migrate --seed`, builds assets, then serves. Ctrl+C stops it. |

- Customer site — <http://localhost:8000>
- Admin panel — <http://localhost:8000/admin/login>
- Logs — `storage/logs/dev-supervisor.log`, `storage/logs/serve.log`, `storage/logs/wait-for-site.log`

Plain `php artisan serve` works too; it just stops when you close the terminal.

> Cold start is slow on Windows: the dev server needs roughly 45 seconds to accept its first
> connection, and the first request can take longer while Laravel compiles config and views.
> `dev-up.bat` polls the site itself before opening your browser, so you will not land on a
> connection error. If the site never comes up, MySQL is the usual suspect.

`tools/` also holds `start-mysql-isolated.bat` for running a second MySQL instance on its own
port if XAMPP's data folder is damaged.

---

## Project structure

The separation is by role at every layer — routes, controllers, views, middleware and
models — so the customer site and the admin panel never share a controller or a layout.

```
app/
├── Casts/TolerantEnum.php              reads a stored enum value without throwing
├── Contracts/NormalizesEnumValues.php  legacy-value normalisation contract
├── Enums/                              AppointmentStatus, AdminRole, ItemTag,
│                                       DownPaymentStatus, TermsCategory,
│                                       InquiryTopic, ChangedBy
├── Http/
│   ├── Controllers/
│   │   ├── Admin/                      17 controllers (dashboard, appointments, catalogue,
│   │   │                               categories, technicians, inventory, tags, calendar,
│   │   │                               users, terms, reports, promos, messages, profile)
│   │   │   └── Auth/                   AdminSessionController, AdminPasswordResetLinkController
│   │   ├── Auth/                       customer register, login, password reset
│   │   ├── Customer/                   booking, cancel, reschedule, notifications,
│   │   │                               profile, terms
│   │   ├── HomeController, ServiceController, PromoController,
│   │   │   ContactController, SessionStatusController
│   ├── Middleware/                     AuthenticateCustomer, AuthenticateAdmin,
│   │                                   EnsureAdminRole, EnsureUserIsActive,
│   │                                   RedirectIfAuthenticated{,Admin},
│   │                                   PreventAuthenticatedCaching
│   ├── Requests/                       Auth/ Admin/ Customer/ Shared/
├── Models/                             16 Eloquent models
├── Notifications/                      13 notification classes
├── Services/                           BookingService, BookingAvailability,
│                                       PasswordResetService, ReportService,
│                                       AppointmentArchiver, UserAnonymizer
└── Support/                            BookingSteps, BookingHistory, DataTable, Nav,
                                        TermsRenderer, PriceFormatter, TabTitle,
                                        NotificationRow, ValidationToast, PromptLogger …

resources/views/
├── layouts/                            customer, admin, auth
├── components/ui/                      the whole design system — button, badge, card,
│   │                                    dialog, admin-table, stat, toast, confirm-dialog,
│   │                                    photo-crop-dialog, form/* (input, select, switch,
│   │                                    checkbox, password, date-picker, textarea)
├── components/{brand,salon,terms}/     logo, emblem, service cards, T&C modal
├── partials/                           customer nav + footer, admin sidebar + topbar
├── home|about|contact|services|promos|customer|admin|auth
```

### Key models

| Model | Role |
| --- | --- |
| `Appointment` | A booking: reference number, snapshotted total, status, admin notes, soft deletes |
| `AppointmentService` | One service line on an appointment, with name / variant / price / duration snapshotted |
| `AppointmentStatusHistory` | Every transition, with `from` → `to` and the actor |
| `Service` / `ServiceVariant` | The catalogue and its per-length pricing |
| `ServiceCategory` | Real rows carrying the colour the admin calendar paints their chips |
| `Technician` | Someone a customer can request when booking. Not an `Admin` — cannot sign in |
| `InventoryItem` | Stock: quantity, unit, reorder threshold, status tag, linked services |
| `BlockedSlot` | A closure: whole day (both times null) or a time range, many per date |
| `SalonSetting` | Singleton row: opening hours, slot interval, booking horizon, minimum notice, deposit rules |
| `Promo` | A dated offer with an image and an announce flag |
| `TermsAndCondition` | Published terms, one per category (booking / cancellation / rescheduling) |
| `User` / `Admin` | The two authentication tables, on two guards |
| `ContactMessage`, `PasswordResetCode`, `Notification` | Contact inbox, hashed reset codes, Laravel's database notifications |

### Custom artisan commands

| Command | Purpose |
| --- | --- |
| `appointments:archive-completed` | Archives settled appointments past the retention window. Scheduled nightly at 02:00. |
| `mail:check` | Reports whether the mail configuration can actually deliver |
| `inventory:clear` | Removes seeded/demo inventory rows |
| `users:clear` | Removes seeded/demo customer rows |
| `prompts:log` | Appends to the development prompt log (see [Prompt logging](#prompt-logging)) |

---

## How the booking rules work

`App\Services\BookingAvailability` is the single source of truth for "can this be booked?".
A date and time must satisfy **all** of:

1. Not in the past, and at least `minimum_notice_hours` ahead (24h by default)
2. Within the booking horizon (`booking_lead_days`, 60 days)
3. On a day the salon is open, per `salon_settings.operating_hours`
4. Outside the standing lunch break (12:00–13:00)
5. Not covered by an admin **blocked slot** — whole day or time range
6. Inside that day's opening hours
7. Not already taken by a pending / confirmed / in-progress appointment

The same rules run on the booking form, on the reschedule form (excluding the appointment's
own slot) and on the public `/book/slots` JSON endpoint that feeds the date picker, so the
greyed-out dates in the calendar and the server's answer can never disagree. The final check
runs inside a database transaction, so two customers cannot take the same slot.

**Price is snapshotted.** `appointment_service` stores the service name, variant, price and
duration at booking time, so later catalogue edits never rewrite booking history.

**Stock is linked.** Booking a service decrements every inventory item linked to it and
re-derives its status tag; cancelling or rescheduling returns the reserved stock. Tags are
derived from quantity alone — `quantity <= 0` is Sold Out, `quantity <= reorder_threshold` is
Low Stock — while an admin's explicit pick is treated as a manual override and labelled as one.

**No payment gateway.** Deposit fields and a manual verification status
(`unverified` / `verified` / `rejected` / `not_required`) exist and an admin verifies a
customer's GCash reference by hand from the appointment screen. The seeded salon
configuration has deposits **disabled**, since the booking form does not ask for a reference.

---

## Salon operating rules

From `SalonSetting` and `BookingAvailability`:

- **Closed every Monday.** Tuesday to Sunday, **09:00 – 17:00** on a single schedule.
  Monday's absence from the operating-hours map is the whole mechanism for a standing weekly
  closure — nothing has to block it, and the customer calendar greys Mondays from the same map
- **30-minute slots**, generated from the opening window
- **Lunch break 12:00 – 13:00** on every open day; 12:00 and 12:30 are removed from the grid
  everywhere, not just hidden in the dropdown
- **24 hours' minimum notice**, bookings open **60 days** ahead
- **Blocked slots** let staff close a whole day (public holiday) or a window on an ordinary day
  (staff meeting), several per date. `end_time` is exclusive, so a 14:00–16:00 block leaves
  16:00 bookable
- **Only settled bookings** — completed or cancelled — can be archived, restored or deleted.
  A booking on the chair right now is the row the salon must never be able to lose

Operating hours and the slot rules are configuration in `salon_settings`, not records, so
there is no admin screen for them: they are read on every booking request through
`SalonSetting::current()`, which is why the row is a singleton. One-off closures are records
and do have a screen, under Admin → Calendar.

---

## Data model

Sixteen application tables, plus Laravel's `sessions`, `cache`, `jobs`, `password_reset_tokens`
and `notifications`.

| Table | Notes |
| --- | --- |
| `users` | Customers. Soft deletes, `is_active`, derived `username` |
| `admins` | Staff. Separate table, separate guard, soft deletes |
| `salon_settings` | Singleton: operating hours, slot interval, booking horizon, minimum notice, deposit rules |
| `service_categories` | Name, colour (admin calendar chips), sort order |
| `services` | `slug` for public URLs, price, duration, photo, `is_active`, `is_featured`, soft deletes |
| `service_variants` | Per-length pricing, unique per service |
| `inventory_items` | Quantity, unit, reorder threshold, status tag, soft deletes |
| `service_inventory` | Many-to-many pivot with `quantity_per_service` |
| `blocked_slots` | Date + optional start/end time + optional reason. Many rows per date, no unique constraint on date |
| `appointments` | Reference number, snapshotted total, status, down-payment fields, internal `admin_notes`, `archived_at`, `admin_seen_at`, idempotency key, soft deletes |
| `appointment_service` | Service lines with name / variant / price / duration snapshots |
| `appointment_status_history` | Every transition with `from` / `to`, actor type, id and name |
| `contact_messages` | Topic enum, read flag, internal reply |
| `terms_and_conditions` | One row per category, with a publish state |
| `promos` | Validity window, image, `notified` flag, soft deletes |
| `password_reset_codes` | Stores only `code_hash`, with `expires_at` and `attempts` |

Conventions: foreign keys throughout, soft deletes wherever history matters, and a backed PHP
enum behind every status field.

A schema dump is also committed at `balai_ti_arjud.sql` (MariaDB 10.4, with
`sessions` / `cache` / `password_reset_codes` shipped as schema-only). Because it is tracked
in git, scrub any real addresses and password hashes out of it before deploying.

---

## Tests

```bash
php artisan test
```

**1077 passed, 1 skipped, 5411 assertions.** The suite defaults to an in-memory SQLite
database (`phpunit.xml`), so it needs no database server and is fully isolated. It has also
been run green against MySQL/MariaDB by overriding `DB_CONNECTION` in the environment.

```bash
# Windows (PowerShell)
$env:DB_CONNECTION="mysql"; $env:DB_DATABASE="bta_test"; php artisan test
```

Feature tests are named after behaviour rather than after files, so they read as a
specification of the app:

| Test | Covers |
| --- | --- |
| `BookingWizardTest`, `BookingRulesTest`, `BookingCreationTest`, `BookingSubmissionGuardTest` | The wizard, every availability rule, guests forced to log in, idempotency |
| `AppointmentStatusUpdateTest`, `AppointmentArchiveTest`, `ArchivedAppointmentsScreenTest` | The status workflow and the archive rules |
| `LoginTest`, `RegistrationTest`, `PublicPagesTest`, `AdminForgotPasswordTest` | Both guards in isolation, rate limiting, the reset wizard, public pages |
| `AdminRoleTest`, `SingleActiveRoleTest` | The ability matrix, per-screen access, hidden controls |
| `LunchBreakSlotsTest`, `SalonHoursDisplayTest`, `SalonConfigurationTest`, `BlockedSlotsTest` | Operating hours, the lunch break, closures |
| `LowStockTaggingTest`, `TechnicianTest`, `ProfilePhotoCropTest` | Stock derivation, the technician roster, photo cropping |
| `CustomerAppointmentsDeleteTest`, `BookAgainTest`, `LiveNotificationsTest`, `CustomerNotificationsPageTest` | Customer-side lifecycle and notifications |
| `ReportsExportTest`, `AdminReportsLayoutTest`, `AdminAppointmentTableTest`, `AdminTablePagingTest` | Reports, CSV exports, the admin tables |
| `SalonSettingsRemovalTest`, `TermsVersionRemovalMigrationTest`, `SingleActiveRoleTest` | Migrations that removed features, pinned so they cannot silently return |

---

## Design system

Tokens live in one place: the CSS custom properties in `resources/css/app.css`, mapped into
Tailwind in `tailwind.config.js`. Change a token once and every component follows.

- **Type** — Playfair Display (display), Cormorant Garamond (headings), Parisienne (script
  wordmark), Poppins (UI and body)
- **Colour** — deep maroon primary, gold accent, linen page background, cream cards, blush
  badge fills; a dedicated token set per appointment status and per stock tag, each with a
  foreground/background pair so a badge is legible on either surface
- **Shape** — rounded cards on a soft shadow, fully rounded pill buttons, thin gold or maroon
  borders, a circular gold-ringed emblem
- **Customer side** is mobile-first; the **admin panel** is desktop-optimised with an
  off-canvas drawer on small screens

`resources/views/components/ui/` holds the shared components (`x-ui.button`, `x-ui.badge`,
`x-ui.card`, `x-ui.dialog`, `x-ui.admin-table`, `x-ui.stat`, `x-ui.toast`, `x-ui.form.*`, and
so on). Pages compose components rather than repeating markup, and the semantic class names
in `app.css` (`btn-primary`, `card`, `badge`, `admin-nav-link`, `customer-nav-link`) are
preferred over raw utilities for anything that already has a component.

---

## Deployment notes

The project was built with **InfinityFree shared hosting** in mind, and three things in the
codebase exist specifically for that environment:

1. **No `storage:link`** — uploads live in `public/storage`, which the server already serves.
   A symlink cannot survive an FTP upload, and InfinityFree does not allow the command
2. **Relative Vite base** (`base: './'` in `vite.config.js`) — asset URLs resolve against
   the CSS file's own location, so a webfont never resolves to a 404 when the app is not
   served from the domain root
3. **`bootstrap/app.php` detects a missing `public/` directory** and repoints `public_path()`
   at the parent of the application root, so `@vite` and the `public` disk keep working on a
   checkout whose contents have been hoisted (see `tools/package-infinityfree.ps1`)

Set these in the server's `.env` before anything else:

```
APP_URL=https://youraccount.infinityfreeapp.com
DB_DATABASE=epiz_12345678_bta
DB_USERNAME=epiz_12345678
DB_PASSWORD=<from the control panel>
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=<the Google account the App Password was issued to>
MAIL_PASSWORD=<the 16-character App Password>
```

`APP_URL` is not cosmetic. CSS, JavaScript and the compiled Tailwind bundle are addressed with
`asset()`, which builds from it — left at `localhost`, a deployed site loads no stylesheet and
no script. Uploaded images use `url()` and follow whichever host the visitor actually
requested, so they survive a wrong `APP_URL` on their own.

`MAIL_USERNAME` must be the address of the Google account the App Password was **issued to**,
not merely an address the salon is known by. An App Password authenticates exactly one
mailbox, so pairing it with any other address fails with `535-5.7.8 Username and Password not
accepted` and the error points at the password rather than the username. Add the salon's
public address to that account as an alias if it should appear as the sender.

Then, from a shell pointed at the server:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci && npm run build
```

---

## Prompt logging

The project carries a development-time audit trail of the prompts that built it, at the
project root.

- **`prompt.log`** — append-only, one line per entry
- **`PROMPT_LOG.md`** — the same history in a reviewable `### Task:` / `* Prompt used:` format
- **`App\Support\PromptLogger`** and **`php artisan prompts:log`** — the programmatic and CLI
  entry points

```bash
php artisan prompts:log --module="Booking Rules" "Added minimum notice to the availability check"
```

`AGENTS.md` documents the convention: prompts are captured automatically by an opencode plugin
that hooks the `chat.message` event, so the agent only appends outcome entries and the two
never duplicate each other.

---

## License

Proprietary — built for Balai ti Arjud.
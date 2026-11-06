# Balai ti Arjud — Online Appointment & Inventory Management System

**Balai ti Arjud — Glow & Co. Beauty Lounge** is a salon management web application built on
Laravel 11. It serves two audiences from one codebase: **customers**, who browse the service
catalogue and book, reschedule or cancel appointments at a beauty lounge, and the **salon's
staff**, who run the business from a separate admin portal working the appointment queue,
maintaining the service catalogue, technicians and stock, publishing terms, promos and
reports.

The project was built as a full-stack capstone: a real schema, a real booking engine with
server-side availability rules, two isolated authentication guards, a Blade component design
system, and an end-to-end feature test suite covering both sides of the app.

---

## Demo credentials

Seeded by `AdminSeeder`. These are development credentials — change or remove them before
deploying anywhere public.

### Staff / admin — `/login` (the same form customers use)

| Field | Value |
| --- | --- |
| Username | `admin` |
| Email | `admin@balaitiarjud.test` |
| Password | `password` |

There is one admin account and no tiers. The seeder force-deletes any other admin row so a
re-seed cannot quietly re-introduce them.

### Guards are isolated

Customers authenticate on the `web` guard against the `users` table; staff authenticate on the
`admin` guard against the `admins` table. Both use the single `/login` form: credentials are
checked against both providers and the authenticated role decides where the browser lands
(admin dashboard or customer home). `/admin/login` is kept as a redirect to `/login` so old
bookmarks, emails and links still work. The guards share one session cookie but have separate
providers and session namespaces, so a customer session can never reach `/admin` and only one
role is active per browser at a time. Customer routes additionally require an active account
(`user.active`), and authenticated pages send `no-store` cache headers so Back after a logout
cannot redraw a panel.

---

## Scheduled cleanup

Notifications older than 30 days are deleted. Completed appointments and appointment status
history older than 30 days are archived with `archived_at` (not deleted), so reports and
customer/order history keep working while the active admin screens stay small.

Run it manually with:

```bash
php artisan records:cleanup          # the old app:archive-old-records name still works
php artisan records:cleanup --dry-run
```

Locally, run the scheduler with `php artisan schedule:work` (or use `dev-up.bat`, which starts
it alongside the server). In production, add the standard Laravel cron entry:

```cron
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

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

### Staff / admin — `/admin/login`

| Field | Value |
| --- | --- |
| Username | `admin` |
| Email | `admin@balaitiarjud.test` |
| Password | `password` |

There is one admin account and no tiers. The seeder force-deletes any other admin row so a
re-seed cannot quietly re-introduce them.

### Guards are isolated

Customers authenticate on the `web` guard against the `users` table; staff authenticate on the
`admin` guard against the `admins` table. They share one session cookie but have separate
providers and session namespaces, so a customer session can never reach `/admin` and staff
credentials are rejected by the customer login form. Customer routes additionally require an
active account (`user.active`), and authenticated pages send `no-store` cache headers so Back
after a logout cannot redraw a panel.

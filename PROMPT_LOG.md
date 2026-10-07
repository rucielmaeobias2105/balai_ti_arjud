# Prompt Log

This is a curated version of the full prompt history for the Balai ti Arjud
system, keeping only the prompts that actually define or change product
behavior/UI. Routine chatter ("continue", "done??", "push all"), deployment
troubleshooting pastes containing credentials/config, and tooling-only
requests (OpenCode plugin/config fixes) have been removed for clarity and
safety. See the full session logs if the complete history is ever needed.

### Task: Tracking prompts in PROMPT_LOG.md
* Prompt used: "Prompt Log Format - Create a file named PROMPT_LOG.md in the root directory of your project using this format: ### Task: [e.g., Creating the Education Section] * Prompt used: \"Create a responsive Blade component for my educational history using Tailwind CSS."

### Task: Building the Balai ti Arjud salon & spa booking + inventory platform (first prompt)
* Prompt used: "Balai ti Arjud — Online Appointment & Inventory Management System

You are building a full production-ready web application for Balai ti Arjud (Glow & Beauty Lounge), a salon/spa booking and inventory management platform. Follow every instruction below precisely. Work incrementally, module by module, and confirm each module compiles/migrates/tests cleanly before moving to the next.

1. Tech Stack (mandatory)
Backend Framework: Laravel (latest stable LTS version)
Database: MySQL
Frontend: Laravel Blade + Tailwind CSS (or Laravel's default frontend scaffolding), with Alpine.js/vanilla JS for interactivity. Use Livewire if it simplifies dynamic UI (calendar, filters, toggles) — your call, but keep it consistent across the app.
Auth: Laravel Breeze or Fortify for customer + a separate guarded admin auth flow (do not mix customer and admin sessions/roles).
File storage: Laravel's filesystem (local storage/app/public with symlink) for profile pictures, service photos, promo images.
Migrations & Seeders: Provide full migrations for every table implied below, plus seeders for demo data (sample services, inventory items, admin account, sample customers).

2. Logging Requirement (mandatory)
Create a prompt.log file at the project root. Every time this application (or any AI-assisted tooling working on it) processes a prompt/instruction during development, append an entry to prompt.log with a timestamp and the prompt text/summary.

3. Design System — Follow the Attached Mockup Exactly
Typography: Headings in Playfair Display (serif), body/UI text in Poppins or Inter.
Color Palette: deep maroon/burgundy primary (#6E2A33), gold/mustard accent (#C9A24B), cream/linen background (#F6EFE3/#FBF7EF), sienna/blush secondary accents.
Status badges: Pending (muted gold/tan), Confirmed (green), In Progress (amber), Completed (solid green), Cancelled (red/maroon), Low Stock (tan/orange), Best Seller (gold), Sold Out (red).
Component styles: rounded cream cards with soft shadow and thin gold/maroon border accent; solid maroon primary buttons; outlined secondary buttons; show/hide toggle links; service cards with thumbnail/name/price/duration/Book Now; maroon sidebar nav for admin; circular emblem logo in Playfair Display.
Build a shared Blade layout/component system (layouts/customer, layouts/admin, components/) for consistency.

4. Customer-Side Modules (15 flows): Register, Login, Forgot Password (multi-step with code), Browse Services, Book Appointment (service, details, date/time, allergies, last service availed, stylist, special request, down payment reference, T&C, auto booking summary), My Appointments/Transactions, Cancel Appointment, Reschedule Appointment, Notifications, Rate Service, Profile Management, Contact Us, Customer Dashboard, Landing/Home, Refined services grid.

5. Admin-Side Modules (14 flows): Admin Login, Admin Dashboard, Appointment Management, Service & Item overview, Service Management, Inventory/Item Management, Low-Stock/Status Tagging, Calendar & Blocked Dates, Registered Users Management, Terms & Conditions Editor, Review/Ratings Moderation, Sales/Usage Reports, Promo/Announcements Management, Admin Navigation Shell.

Design normalized MySQL tables for: users, admins, services, service_variants, inventory_items, service_inventory (pivot), appointments, appointment_status_history, reviews, notifications, contact_messages, blocked_dates, terms_and_conditions (versioned), promos, password_reset_codes. Use foreign keys, soft deletes, enums where appropriate."

### Task: Replace JavaScript Alert with Custom HTML/CSS Confirmation Modal and Fix Modal Backdrop Styling
* Prompt used: "Replace Browser Native confirm() with a Custom Modal — update the calendar and blocked dates view so clicking 'Mark Available' opens a custom HTML/CSS modal (title, confirmation body, Cancel + primary 'Confirm'/'Mark Available' button, close icon) matching the 'Block Date' modal's design, instead of a native JS confirm() dialog. Also replace the heavy reddish/tinted modal backdrop blur with a soft warm cream/beige translucent backdrop (e.g. rgba(245,240,235,0.6) + blur(4px)), applied consistently to all modals. Maintain existing form submission/AJAX logic."
* Recorded: 2026-09-29

### Task: New features — Salon Settings, time-range blocking, booking idempotency, Book Again
* Prompt used: "Apply the following features to the Balai ti Arjud system, in order:
1. ADMIN SALON SETTINGS SCREEN — operating hours, slot interval, booking lead time, admin-editable down payment percentage, default weekly closure days; wire live into booking availability logic.
2. TIME-RANGE BLOCKING (fresh blocked_slots schema: date, start_time, end_time nullable = whole day, reason, created_by) — admin Calendar/Blocked Slots page (month view, click a date to add ranges, list with edit/remove); customer date/time picker disables fully blocked days and greys out blocked time ranges on partially blocked days, with a toast error on invalid selection.
3. RATE-LIMIT + IDEMPOTENCY on POST /book — throttle middleware, client-generated idempotency key, server-side duplicate-submission guard, disable Confirm button on click.
4. 'BOOK AGAIN' button on Completed appointments in My Appointments — pre-fills a new booking with the same service(s), requires a new date/time before confirming."
* Recorded: 2026-10-04

### Task: Match MCA Café blocked-date styling + remove Salon Settings feature
* Prompt used: "Match MCA Café's exact visual treatment for blocked dates on the booking calendar: fully blocked dates show a RED STRIKETHROUGH through the date number (not just greyed out); partially blocked dates stay normally selectable; add a red bottom-right toast ('This date is not available. Please choose another date.') matching MCA's style in addition to any existing inline warning. Separately, remove the Salon Settings admin page/menu/route entirely, but do not break booking availability, slot generation, or down payment calculation if they depend on SalonSetting data — either keep it as backend-only config or flag exactly what depends on it before removing anything."
* Recorded: 2026-10-04

### Task: Replace inline calendar grid with dropdown date picker + polish pass
* Prompt used: "On Book Appointment's date/time step, remove the large always-visible inline 'AVAILABILITY' calendar grid and replace the Preferred Date field with a standard input that opens a dropdown/popup calendar (closes on selection or click-away), using MCACAFE_v1 as the direct structural/behavioral reference for this component only (not for anything else), restyled to Balai ti Arjud's maroon/cream theme. Blocked dates show the same red strikethrough; selecting one still triggers the toast warning. Then run a bug-fix-only polish pass across the whole system: broken/dead links from removed features, inconsistent spacing/styling, console errors, lingering references to removed features, notification polling performance, responsive behavior, and form validation — no new features, flag anything needing a product decision instead of deciding unilaterally."
* Recorded: 2026-10-04

### Task: Restructure Book Appointment wizard from 7 steps + fix date picker messaging
* Prompt used: "Merge Step 2 ('Your Details') + Step 3 ('Preferred Date & Time') into one step; update 'STEP X OF Y' numbering throughout. Remove the redundant inline error text under Date/Time fields ('This date is not available...' / 'No open slots on this date...') now that the toast already covers it. Confirm the dropdown calendar with red-strikethrough blocked dates still works after the merge. Change 'Preferences & Health Notes' from a dropdown back to free-text. Merge Step 5 ('Last Service(s) Availed') + Step 6 ('Choose Technician & Special Request') into one step. Remove Step 7 ('Terms & Conditions') as its own step and move the T&C agreement checkbox (with its Booking/Cancellation/Rescheduling modal links) onto the new last step's card, just above Confirm Booking. Verify the full flow end-to-end after restructuring."
* Recorded: 2026-10-04

### Task: Booking/calendar fixes — strikethrough, contrast, native dialogs, step validation, business hours
* Prompt used: "1) Fix admin-blocked dates not showing the red strikethrough in the customer dropdown calendar immediately on open (not only after a failed selection). 2) Fix low-contrast/washed-out calendar date numbers on the admin Calendar & Blocked Slots page to match MCA Café's readable dark text. 3) Keep the Blocked Dates summary list table as-is, just inherit the contrast fix. 4) Replace EVERY remaining native browser confirm()/alert()/prompt() across admin and customer sides with custom modals matching the established Cancel/Confirm pattern — specifically the 'Remove closed all day on [date]?' confirmation, and audit for any other native dialogs left anywhere. 5) Require all necessary fields on each Book Appointment step before allowing Continue (disable button or show inline errors). 6) Restrict Preferred Time to 9:00 AM–5:00 PM, salon closed every Monday — disable Mondays in the date picker as a recurring weekly closure (separate from one-off admin blocks), and only list valid time slots respecting the slot interval. 7) Remove the 'Preferences & Health Notes' field entirely from the booking form (flag if dropping the DB column is risky). Also: remove the duration labels (e.g. '1 hr', '1 hr 15 min', '30 min') from the service list, showing only prices."
* Recorded: 2026-10-04

### Task: Rename "Who would you like to see?" label
* Prompt used: "'Who would you like to see?' change to 'Choose Your Technician'"
* Recorded: 2026-10-04

### Task: Terms & Conditions checkbox warning must not reset the wizard to step 1
* Prompt used: "'You must agree to the Terms and Conditions to book.' — checking the terms and condition warning must keep the user on step 4 of the form, not reload back to step 1."
* Recorded: 2026-10-04

### Task: System-wide text removals and fixes (homepage, footer, promo cards, auth pages, appointment modal, &amp; bug, admin calendar helper text)
* Prompt used: "1) Remove the subtitle 'The treatments that come back to the chair most often.' from the Homepage's Most Booked Services section (keep the heading and cards). 2) Remove the footer text containing 'from glow manicures to...'. 3) Make all promo cards a fixed, consistent size regardless of content length, with overflowing content auto-fitting (truncation/expand, font scaling, or scroll) rather than resizing the card. 4) Remove the subtitle lines 'Log in to your account to book appointments and manage your bookings.' and 'Create your customer account and start booking.' from the Login/Register pages (keep the headings). 5) Remove the entire 'Status History' section (e.g. 'Created → Pending ... Appointment submitted by customer.') from the Appointment Details modal, keeping Appointment Information/Payment Summary/Service(s). 6) Audit the whole codebase for '&amp;' rendering literally instead of '&' (seen in 'Preferences &amp; Health Notes', 'Choose Technician &amp; Special Request', etc.) and fix every instance across all pages. 7) Remove four specific helper/explanatory text blocks from the admin Calendar & Blocked Slots page (the 'end time is not included...' note, the 'Blocked all day · Blocked periods · Closed · No opening hours' run-on text if separate from the functional legend, 'Click a date to add or remove blocks.', and the 'Customers can currently book Oct 6–Dec 4, 2026...' note) without removing any functional calendar/legend/list UI."
* Recorded: 2026-10-05

### Task: Admin panel fixes (appointments, services delete, helper-text cleanup) and promo card redesign to match MCA Café
* Prompt used: "ADMIN — Appointments: when status changes to Completed, the Delete and Archive buttons must appear automatically in real time via the existing live-update mechanism, no manual refresh needed; remove the helper text 'A completed service is final and cannot be changed.' (keep the disabled-dropdown behavior). ADMIN — Services: fix the Delete button, which currently 404s instead of opening the confirmation modal and deleting. ADMIN — Edit Category/Technician/Promo: remove their image-upload helper/hint texts (file type/size notes, 'An inactive technician keeps their existing bookings...', etc.) while keeping the actual upload fields and toggles working. CUSTOMER — Promo card layout: reverse the earlier 'fixed card size' approach and instead match MCA Café's 'Anniv' pattern — a reasonably sized FIXED image plus a TEXT column that grows naturally with content (scrollable or variable card height) so long promo descriptions are never cut off, while keeping the badge/title/validity/Book Now elements in their established positions."
* Recorded: 2026-10-05
# Bella Vista Oasis Spa Management System

Admin portal for Bella Vista Oasis Spa (school final project). PHP 8, PDO, MySQL, vanilla JavaScript. Runs on localhost with XAMPP.

The spa is open daily, Monday to Sunday, 1:00 PM to 9:00 PM. The Admin portal and the customer portal (`customer/`) share one database and one booking engine.

## Setup (XAMPP)

1. Install XAMPP with PHP 8 or newer and start **Apache** and **MySQL**.
2. Copy the project folder into `C:\xampp\htdocs\` and name it exactly `bella-vista-oasis` (lowercase, the URLs below depend on it).
3. Open http://localhost/phpmyadmin, create a database named `bella_vista_oasis` (collation `utf8mb4_unicode_ci`).
4. Select that database, open **Import**, choose `database/bella_vista_oasis.sql` and click **Import**.
5. Database settings are in `admin/config/database.php` (default: host `localhost`, user `root`, empty password). Change them only if your MySQL differs.
6. Open http://localhost/bella-vista-oasis/admin/

## Admin login

- URL: http://localhost/bella-vista-oasis/admin/auth/login.php
- Email: `director.wellness@bellavista-oasis.com`
- Password: the account password stored in the imported database. If you do not know it, run this in phpMyAdmin (SQL tab) to set it to `BellaVista@2026`:

```sql
UPDATE administrators SET password = '$2y$12$nM2pr3FGXIsZq4Xbfx9HQOMb9C9nsYBdyftlzTSAsUIadZ30vo2G2' WHERE id = 1;
DELETE FROM login_attempts;
```

After 5 failed logins an account is locked for 15 minutes; the second statement clears that.

## Main pages

| Page | URL (after /bella-vista-oasis/admin/) |
| --- | --- |
| Dashboard | dashboard.php |
| Appointments | appointments.php |
| Appointment Calendar | appointment-calendar.php |
| Customers | customers.php |
| Therapist Assignment | therapist-assignment.php |
| Room Management | room-management.php |
| QR Check-in | qr-checkin.php |
| Memberships & Loyalty | memberships.php |
| Reports (daily report first) | reports.php |
| Analytics | analytics.php |
| Settings (hours, rates, blocked dates) | settings.php |

## Services, rooms and rates

| Service | Rooms | Capacity | Rate |
| --- | --- | --- | --- |
| Single Room Massage | SR-1, SR-2, SR-3 | 1 | PHP 800 / 1 hour |
| Couples Suite | CS-1, CS-2 | 2 | PHP 1,800 / 1 hour |
| Barkada / Family Spa Lounge | FL-1, FL-2 | 4 (max 6) | PHP 4,000 / 1.5 hours, plus PHP 750 per extra person beyond 4 |

## Main features

- Booking rules enforced on every booking path: operating hours, blocked and closed dates, fixed start-time grid, room must match the service, guest capacity, server-computed total with the extra-person fee, and an automatic 30-minute cleaning gap between sessions in the same room.
- Date picker that disables unavailable dates (past, closed, blocked, fully booked, maintenance) and explains why.
- Time slot picker showing only valid free start times for the chosen service, date and room.
- Health declaration (medical conditions, injuries, pregnancy) required on every booking, with a pregnancy badge.
- Policies: 6-hour cancellation and reschedule notice (late cancellation forfeits the downpayment), downpayment and balance tracking, late-arrival handling at QR check-in with a 15-minute arrival reminder.
- Appointment Calendar with real-time room bookings, In Session status and cleaning-gap blocks for all 7 rooms.
- Therapist Assignment for bookings without a therapist, limited to therapists who are on shift, not on leave and not double-booked, with an assignment log.
- Daily Report: bookings for the day, active therapist schedules, and total revenue (paid payments plus downpayments).
- Customer management, room management, memberships and rewards, analytics and settings.

## Customer portal and Admin connection

- Customer site: http://localhost/bella-vista-oasis/customer/ (register, sign in, services, book, loyalty, dashboard).
- Both sides use the same database (`admin/config/database.php`) and separate sessions (`BVO_CUSTOMER_SESSION` and `BVO_ADMIN_SESSION`).
- Customer booking (`customer/book-appointment.php`) uses the Admin rules engine (`admin/includes/booking-rules.php` and `availability-data.php`): operating hours, blocked dates, start-time grid, room capacity, cleaning gap, maintenance rooms and server-computed totals.
- A room is assigned automatically. The booking is saved as `pending` and shows in Admin Appointments, Calendar, Therapist Assignment (no therapist yet) and Dashboard, with an admin notification.
- Admin confirm, cancel and reschedule actions show on the customer dashboard. Customers can cancel upcoming bookings using the same cancellation policy.
- Customers registered online appear in Admin Customer Management with their loyalty welcome bonus.
- QR Check-In (`customer/qr-checkin.php`): the customer generates the arrival pass for a confirmed booking using the Admin QR engine (`admin/includes/qr-checkin-data.php`). The front desk scans it in Admin QR Check-in, and the customer page then shows the booking as checked in.
- Services, rates, hours, contact details, cleaning gap, arrival and cancellation notices, rooms, membership tiers and rewards on the customer pages are all read from the Admin database, so any change in Admin Settings, Room Management or Memberships shows on the customer site.
- Admin delete: Appointment Management has a "Delete Appointment" action (removes the booking, its payments and check-in history). Customer Management has "Delete guest" under More Actions (removes the guest with their appointments, payments, membership and loyalty records). Both ask for confirmation and cannot be undone.
- Room Management has a Delete button on each suite. A room with any booking on record cannot be deleted (use Maintenance instead); otherwise its details, climate and maintenance records are removed too.

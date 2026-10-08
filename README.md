<div align="center">

<img src="images/logo.png" alt="NARAK project logo" width="155">

# NARAK | نرعاك

### Laboratory Booking & Test Results Management

**IT320 · Practical Software Engineering · King Saud University**

**PHP** · **MySQL** · **HTML** · **CSS** · **JavaScript** · **Arabic RTL**

[Explore Features](#features) · [System Screens](#interface-gallery) · [Setup Guide](#run-locally) · [Team](#team)

</div>

---

NARAK is an academic, Arabic-first web application connecting customers with laboratories. It supports laboratory test booking, appointment management, result viewing and comparison, and role-specific dashboards for customers, laboratories, and administrators.

> **Educational prototype:** Not intended for clinical use, real patient information, or production deployment. The included demo data is fictional.

## Problem and solution

Laboratory booking and follow-up may require separate processes for finding an available appointment and reviewing results. NARAK explores a single interface for customers and laboratory staff to coordinate bookings and view test information.

## Interface gallery

> **Screenshots pending:** Actual screenshots of the running application will be added after the local PHP/MySQL setup is verified. The existing `images/` folder contains site assets, not verified screenshots of the running dashboards. We will not use fabricated UI images.

| Screen | What to showcase |
|---|---|
| Sign-in & registration | Arabic RTL onboarding and role-based entry |
| Customer dashboard | Laboratory discovery, booking and results |
| Laboratory dashboard | Time slots, appointments and results entry |
| Admin dashboard | Reports and user administration |

Once captured, save them in `docs/screenshots/` and embed them here using Markdown images, e.g. `![Customer dashboard](docs/screenshots/customer-dashboard.png)`.

## Features

| Customer | Laboratory | Administrator |
|---|---|---|
| Register and sign in | Manage available time slots | Manage accounts and laboratories |
| Browse labs and tests | Review appointments | Review laboratory-submitted reports |
| Book, change or cancel bookings | Enter test results | Manage account restrictions |
| View and compare results | Report customer-related issues | Monitor activity through a dashboard |

The application includes role-based session checks in server-side PHP endpoints. This does **not** mean it has been production security-audited.

## Technology stack

- **Frontend:** HTML5, CSS3, JavaScript, RTL Arabic interface
- **Backend:** PHP and MySQLi
- **Database:** MySQL
- **Development:** Git, GitHub, local PHP/MySQL server

## Repository guide

| File | Purpose |
|---|---|
| `index.php` | Login and registration |
| `signup_process.php`, `login_process.php` | Account creation and login |
| `customer-dashboard.php` | Customer area |
| `lab-dashboard.php` | Laboratory area |
| `admin-dashboard.php` | Administration area |
| `book_appointment.php` | Appointment creation |
| `update_appointment.php`, `cancel_appointment.php` | Appointment changes |
| `get_available_slots.php` | Published availability API |
| `save_test_results.php` | Laboratory result entry |
| `db.php` | Local database configuration |
| `dummy_data.sql` | **Fictional demo rows only** |

## Run locally

1. Install PHP with MySQLi and a local MySQL server (e.g., XAMPP).
2. Import [`database/schema.sql`](database/schema.sql) into a **new empty local database** to create the tables. This sanitized schema intentionally removes the original unique constraint on `appointment.slot_id`, so a cancelled appointment's released time slot can be booked again. Do not import it into an existing database without a reviewed migration.
3. Configure `NARAK_DB_HOST`, `NARAK_DB_PORT`, `NARAK_DB_NAME`, `NARAK_DB_USER`, and `NARAK_DB_PASSWORD` in your *local* environment. Do not commit credentials.
4. The optional `dummy_data.sql` contains example rows, **not** the complete schema or necessarily all prerequisite laboratory/test rows. Do not import it into a real or shared database; it may not match the fresh schema without adjustment.
5. Run `php -S localhost:8000` from the project directory and open `http://localhost:8000/index.php`.

**Deployment note:** This is a course demonstration and has not been independently end-to-end tested. Further hardening, CSRF protection, authorization review, and concurrency testing are needed before any public deployment.

## Demo and availability

**Live demo:** Not available yet. GitHub Pages cannot execute PHP/MySQL, so linking directly to `index.php` on GitHub is **not** a functioning website. The project must be hosted on a PHP/MySQL-capable server or demonstrated locally. Avoid publishing real customer or medical data.

## Software engineering documentation

The academic software engineering report can be placed at `docs/NARAK-Software-Engineering-Report.pdf` once checked for private student information and consistency with the implemented system.

## Team & contributions

This is a **collaborative student project**. Individual responsibilities can be added when confirmed by the team.


Developed collaboratively for IT320 at King Saud University by:

- Noora Alsaiari
- Yara Zakzouk
- Norah Al Hussain
- Farah Alhamed

## Future enhancements

Automated integration tests, stronger security controls, richer notifications, improved responsive layouts, and production-ready database migration scripts.

## Disclaimer

This is a university project. It is **not** a certified healthcare provider, a validated medical-device application, or a real clinical records system.
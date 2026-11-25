# Stacey Laundry Hub

This project contains the Stacey Laundry Hub management system for local XAMPP/MySQL development.

## Project structure

- `public/` = shared landing/redirect entry point
- `stacey_laundry/` = the laundry management system
- `setup.php` = rebuilds and seeds the MySQL database

## Local setup

1. Start Apache and MySQL from XAMPP.
2. Ensure the MySQL root user is available with no password (default XAMPP config).
3. Run:

   `php C:\xampp\htdocs\LAUNDRY\setup.php`

4. Open the application:

   `http://127.0.0.1:8000/stacey_laundry/public/login.php`

5. Or run a local PHP server:

   `php -S 127.0.0.1:8000 -t C:\xampp\htdocs\LAUNDRY`

## Demo account access

- Username: `admin`
- Password: `password123`

## Notes

- All forms use PDO prepared statements.
- Passwords are hashed with `password_hash()` and verified with `password_verify()`.
- Login accepts a username or an email address assigned to the account. To upgrade an existing database without deleting its records, apply `stacey_laundry/database/migrations/2026_11_17_login_email_and_remember_tokens.sql` once. Email addresses are optional and can be assigned to accounts by an administrator.
- “Remember this device” uses a random, rotating 30-day token stored only as a SHA-256 hash in MySQL; logout revokes the current device token.
- Seed data is included for demonstration and testing only.
- Shared feedback uses accessible success, error, warning, and information toasts; operational confirmations use an in-app dialog.
- Form input is validated in PHP as well as in the browser. Order creation, status changes, customer creation, and payment recording use database transactions and activity logging.
- Unexpected server errors are logged by PHP and shown with a safe generic error page. Configure PHP's error log with restricted access and review it during maintenance.

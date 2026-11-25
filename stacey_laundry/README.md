# Stacey Laundry Hub

## Overview

Stacey Laundry Hub is a native PHP operations and customer notification system for a small to medium laundry business. It supports order creation, service tracking, customer records, payment monitoring, reports, and dashboard analytics.

## Database

Database name:

- `stacey_laundry_hub`

Schema and seed SQL files are stored in:

- `stacey_laundry/database/schema.sql`
- `stacey_laundry/database/seed.sql`

## Setup

1. Start XAMPP Apache and MySQL.
2. Run the project setup script from the root folder:

   `php C:\xampp\htdocs\LAUNDRY\setup.php`

3. Launch the app with:

   `php -S 127.0.0.1:8000 -t C:\xampp\htdocs\LAUNDRY`

4. Open:

   `http://127.0.0.1:8000/stacey_laundry/public/login.php`

## Demo credentials

- Username: `admin`
- Password: `password123`

## Included features

- Customer management
- Laundry order creation and status tracking
- Payment recording with outstanding balance checks
- In-system notifications for ready-for-pickup and updates
- Reports and CSV export
- Dashboard metrics from live database data

# Stacey Laundry Hub — Database ERD

This documentation describes the live MariaDB database `stacey_laundry_hub` as inspected on 2026-11-26. The ERD is based on the checked-in schema and migrations, cross-checked against `information_schema`. No database rows or structures were changed.

## Deliverables

- [High-resolution ERD PNG](./stacey_laundry_erd.png)
- [Editable Mermaid ER diagram](./stacey_laundry_erd.mmd)

## Architecture summary

The database has 15 InnoDB tables grouped around staff authentication, customer accounts, laundry orders and services, payments, notifications, and support. Relational integrity is enforced with 20 declared foreign-key constraints. There are no separate employee, inventory, configuration, or report tables in this schema; staff/admin identities are stored in `users`, while reports are computed from operational records.

`orders` is the central operational entity. It belongs to one customer, may be attributed to one creating user, and has child rows for service line items, status history, payments, customer notifications, and support messages. `order_items` is the junction between orders and services, so orders and services are many-to-many through that table. Payment records are stored independently from order totals and may be multiple per order.

## Relationship summary

| Parent | Child | FK → referenced key | Cardinality / optionality | Delete behavior |
|---|---|---|---|---|
| `users` | `user_remember_tokens` | `user_id` → `users.id` | 1:N; child FK mandatory | CASCADE |
| `users` | `activity_logs` | `user_id` → `users.id` | 1:N; child FK nullable | Default RESTRICT |
| `users` | `orders` | `created_by` → `users.id` | 1:N; child FK nullable | Default RESTRICT |
| `users` | `order_status_history` | `changed_by` → `users.id` | 1:N; child FK mandatory | Default RESTRICT |
| `users` | `payments` | `created_by` → `users.id` | 1:N; child FK nullable | Default RESTRICT |
| `users` | `customer_support_messages` | `replied_by` → `users.id` | 1:N; child FK nullable | SET NULL |
| `customers` | `customer_accounts` | `customer_id` → `customers.id` | 1:0..1; unique FK, mandatory in account | CASCADE |
| `customers` | `orders` | `customer_id` → `customers.id` | 1:N; child FK mandatory | Default RESTRICT |
| `customers` | `notifications` | `customer_id` → `customers.id` | 1:N; child FK mandatory | Default RESTRICT |
| `customers` | `customer_notifications` | `customer_id` → `customers.id` | 1:N; child FK nullable | CASCADE |
| `customers` | `customer_notification_reads` | `customer_id` → `customers.id` | 1:N; child FK mandatory | CASCADE |
| `customers` | `customer_support_messages` | `customer_id` → `customers.id` | 1:N; child FK mandatory | CASCADE |
| `customer_accounts` | `customer_password_resets` | `customer_account_id` → `customer_accounts.id` | 1:N; child FK mandatory | CASCADE |
| `customer_notifications` | `customer_notification_reads` | `notification_id` → `customer_notifications.id` | 1:N; child FK mandatory | CASCADE |
| `orders` | `order_items` | `order_id` → `orders.id` | 1:N; child FK mandatory | CASCADE |
| `orders` | `order_status_history` | `order_id` → `orders.id` | 1:N; child FK mandatory | CASCADE |
| `orders` | `payments` | `order_id` → `orders.id` | 1:N; child FK mandatory | Default RESTRICT |
| `orders` | `customer_notifications` | `order_id` → `orders.id` | 1:N; child FK nullable | SET NULL |
| `orders` | `customer_support_messages` | `order_id` → `orders.id` | 1:N; child FK nullable | SET NULL |
| `services` | `order_items` | `service_id` → `services.id` | 1:N; child FK mandatory | Default RESTRICT |

`customer_notification_reads` is a junction table with a composite primary key `(notification_id, customer_id)`. It records customer read state for notifications, including announcements whose `customer_notifications.customer_id` is `NULL`. `order_items` is the junction table for the conceptual M:N relationship between orders and services.

## Table dictionary

Notation: **PK** primary key; **FK** foreign key; **UQ** unique constraint/index; **NULL** nullable. `AUTO_INCREMENT`, defaults, and `ON UPDATE` are noted where present. All unmarked columns are `NOT NULL` unless the SQL declaration says otherwise.

### Authentication and staff activity

#### `users` — staff and administrator accounts

| Column | Type | Constraints / notes |
|---|---|---|
| `id` | `int` | PK, AUTO_INCREMENT |
| `username` | `varchar(50)` | UQ |
| `email` | `varchar(254)` | NULL, UQ; nullable unique permits multiple NULLs in MariaDB |
| `password_hash` | `varchar(255)` | |
| `full_name` | `varchar(100)` | |
| `role` | `enum('Administrator','Staff')` | |
| `status` | `enum('active','inactive')` | DEFAULT `'active'` |
| `last_login` | `datetime` | NULL |
| `created_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP |
| `updated_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP |

Indexes: PRIMARY (`id`), UNIQUE (`username`), UNIQUE (`email`).

#### `user_remember_tokens` — rotating persistent-login tokens

| Column | Type | Constraints / notes |
|---|---|---|
| `token_hash` | `char(64)` | PK |
| `user_id` | `int` | FK → `users.id`, indexed |
| `expires_at` | `datetime` | |
| `created_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP |

Indexes: PRIMARY (`token_hash`), `idx_remember_tokens_user_expiry` (`user_id`, `expires_at`).

#### `activity_logs` — audit/application activity

| Column | Type | Constraints / notes |
|---|---|---|
| `id` | `int` | PK, AUTO_INCREMENT |
| `user_id` | `int` | NULL, FK → `users.id` |
| `action` | `varchar(150)` | |
| `details` | `text` | NULL |
| `created_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP |

Indexes: PRIMARY (`id`), FK index (`user_id`).

### Customer accounts and profiles

#### `customers` — customer contact/profile records

| Column | Type | Constraints / notes |
|---|---|---|
| `id` | `int` | PK, AUTO_INCREMENT |
| `full_name` | `varchar(120)` | |
| `contact_number` | `varchar(30)` | |
| `address` | `text` | |
| `registration_date` | `date` | |
| `created_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP |
| `updated_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP |

Indexes: PRIMARY (`id`). The contact number is not declared UNIQUE.

#### `customer_accounts` — customer portal credentials

| Column | Type | Constraints / notes |
|---|---|---|
| `id` | `int` | PK, AUTO_INCREMENT |
| `customer_id` | `int` | FK → `customers.id`, UQ; creates a 1:0..1 customer/account link |
| `email` | `varchar(254)` | UQ |
| `password_hash` | `varchar(255)` | |
| `account_status` | `enum('active','inactive')` | DEFAULT `'active'` |
| `profile_picture` | `varchar(255)` | NULL |
| `last_login` | `datetime` | NULL |
| `created_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP |
| `updated_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP |

Indexes: PRIMARY (`id`), UNIQUE (`customer_id`), UNIQUE (`email`).

#### `customer_password_resets` — expiring reset tokens

| Column | Type | Constraints / notes |
|---|---|---|
| `id` | `int` | PK, AUTO_INCREMENT |
| `customer_account_id` | `int` | FK → `customer_accounts.id`, indexed |
| `token_hash` | `char(64)` | UQ |
| `expires_at` | `datetime` | |
| `used_at` | `datetime` | NULL |
| `created_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP |

Indexes: PRIMARY (`id`), UNIQUE (`token_hash`), `idx_cpr_account_expiry` (`customer_account_id`, `expires_at`).

### Laundry services and orders

#### `services` — service catalog and rates

| Column | Type | Constraints / notes |
|---|---|---|
| `id` | `int` | PK, AUTO_INCREMENT |
| `name` | `varchar(100)` | |
| `price` | `decimal(10,2)` | |
| `unit` | `varchar(20)` | |
| `status` | `enum('active','inactive')` | DEFAULT `'active'` |
| `created_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP |
| `updated_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP |

Indexes: PRIMARY (`id`). Service names are not declared UNIQUE.

#### `orders` — customer laundry orders

| Column | Type | Constraints / notes |
|---|---|---|
| `id` | `int` | PK, AUTO_INCREMENT |
| `order_number` | `varchar(30)` | UQ |
| `customer_id` | `int` | FK → `customers.id`, mandatory |
| `status` | `varchar(30)` | DEFAULT `'Received'` |
| `weight_kg` | `decimal(10,2)` | DEFAULT `0` |
| `quantity` | `int` | DEFAULT `1` |
| `subtotal` | `decimal(10,2)` | DEFAULT `0` |
| `discount` | `decimal(10,2)` | DEFAULT `0` |
| `total` | `decimal(10,2)` | DEFAULT `0` |
| `special_instructions` | `text` | NULL |
| `estimated_completion` | `date` | NULL |
| `created_by` | `int` | NULL, FK → `users.id` |
| `created_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP |
| `updated_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP |

Indexes: PRIMARY (`id`), UNIQUE (`order_number`), FK indexes (`customer_id`, `created_by`).

#### `order_items` — order/service junction and price snapshot

| Column | Type | Constraints / notes |
|---|---|---|
| `id` | `int` | PK, AUTO_INCREMENT |
| `order_id` | `int` | FK → `orders.id`, mandatory |
| `service_id` | `int` | FK → `services.id`, mandatory |
| `quantity` | `int` | DEFAULT `1` |
| `unit_price` | `decimal(10,2)` | |
| `line_total` | `decimal(10,2)` | |

Indexes: PRIMARY (`id`), FK indexes (`order_id`, `service_id`). No uniqueness constraint prevents duplicate service lines per order.

#### `order_status_history` — order status audit trail

| Column | Type | Constraints / notes |
|---|---|---|
| `id` | `int` | PK, AUTO_INCREMENT |
| `order_id` | `int` | FK → `orders.id`, mandatory |
| `old_status` | `varchar(30)` | |
| `new_status` | `varchar(30)` | |
| `changed_by` | `int` | FK → `users.id`, mandatory |
| `changed_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP |
| `notes` | `text` | NULL |

Indexes: PRIMARY (`id`), FK indexes (`order_id`, `changed_by`).

#### `payments` — payments recorded against orders

| Column | Type | Constraints / notes |
|---|---|---|
| `id` | `int` | PK, AUTO_INCREMENT |
| `order_id` | `int` | FK → `orders.id`, mandatory |
| `amount` | `decimal(10,2)` | |
| `payment_method` | `varchar(50)` | |
| `payment_date` | `datetime` | NOT NULL, DEFAULT CURRENT_TIMESTAMP |
| `notes` | `varchar(255)` | NULL |
| `created_by` | `int` | NULL, FK → `users.id` |
| `created_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP |

Indexes: PRIMARY (`id`), FK indexes (`order_id`, `created_by`). No payment-to-refund table or declared refund relationship exists.

### Notifications and customer support

#### `notifications` — original customer notification table

| Column | Type | Constraints / notes |
|---|---|---|
| `id` | `int` | PK, AUTO_INCREMENT |
| `customer_id` | `int` | FK → `customers.id`, mandatory |
| `title` | `varchar(100)` | |
| `message` | `text` | |
| `type` | `varchar(30)` | DEFAULT `'in_system'` |
| `is_read` | `tinyint(1)` | DEFAULT `0` |
| `created_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP |

Indexes: PRIMARY (`id`), FK index (`customer_id`).

#### `customer_notifications` — customer portal notifications and announcements

| Column | Type | Constraints / notes |
|---|---|---|
| `id` | `int` | PK, AUTO_INCREMENT |
| `customer_id` | `int` | NULL, FK → `customers.id` |
| `order_id` | `int` | NULL, FK → `orders.id` |
| `title` | `varchar(150)` | |
| `message` | `text` | |
| `notification_type` | `enum('order','payment','general')` | DEFAULT `'order'` |
| `is_read` | `tinyint(1)` | DEFAULT `0` |
| `created_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP |

Indexes: PRIMARY (`id`), `idx_cn_customer_read` (`customer_id`, `is_read`, `created_at`), FK index (`order_id`). Both FKs are nullable; both may be NULL for a global announcement.

#### `customer_notification_reads` — per-customer read state junction

| Column | Type | Constraints / notes |
|---|---|---|
| `notification_id` | `int` | PK part, FK → `customer_notifications.id` |
| `customer_id` | `int` | PK part, FK → `customers.id` |
| `read_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP |

Indexes: composite PRIMARY (`notification_id`, `customer_id`), FK index (`customer_id`). The composite key disallows duplicate read records for the same customer/notification pair.

#### `customer_support_messages` — support conversations

| Column | Type | Constraints / notes |
|---|---|---|
| `id` | `int` | PK, AUTO_INCREMENT |
| `customer_id` | `int` | FK → `customers.id`, mandatory |
| `order_id` | `int` | NULL, FK → `orders.id` |
| `subject` | `varchar(150)` | |
| `message` | `text` | |
| `status` | `enum('open','resolved')` | DEFAULT `'open'` |
| `staff_reply` | `text` | NULL |
| `replied_by` | `int` | NULL, FK → `users.id` |
| `replied_at` | `datetime` | NULL |
| `created_at` | `timestamp` | NULL, DEFAULT CURRENT_TIMESTAMP |

Indexes: PRIMARY (`id`), `idx_csm_customer_status` (`customer_id`, `status`, `created_at`), FK indexes (`order_id`, `replied_by`).

## Tables without outgoing foreign keys

`users`, `customers`, and `services` have no outgoing FK constraints. They are still referenced as parent tables. `services` is a standalone catalog root; its connection to orders is via `order_items`.

## Constraints and indexes

- Primary keys: all tables have a primary key; `customer_notification_reads` uses composite PK `(notification_id, customer_id)`.
- Unique constraints: `users.username`, nullable `users.email`, `orders.order_number`, `customer_accounts.customer_id`, `customer_accounts.email`, and `customer_password_resets.token_hash`.
- Composite secondary indexes: `user_remember_tokens(user_id, expires_at)`, `customer_password_resets(customer_account_id, expires_at)`, `customer_notifications(customer_id, is_read, created_at)`, and `customer_support_messages(customer_id, status, created_at)`.
- MariaDB has supporting FK indexes for the remaining FK columns.
- Foreign keys use `ON DELETE CASCADE` for dependent tokens, customer-account data, notification read state, and order line/history records where specified. Nullable references use `SET NULL` for `customer_notifications.order_id`, `customer_support_messages.order_id`, and `customer_support_messages.replied_by`. Other constraints use the engine's default RESTRICT/NO ACTION behavior.

## Application-level relationships and limitations

- `users.role` distinguishes `Administrator` and `Staff`; there is no separate employee table.
- Account access is related to a customer by the declared unique FK `customer_accounts.customer_id`.
- The customer portal uses `customer_notifications` and its read-state junction; the older `notifications` table remains a separate customer-linked table.
- The application checks phone/email uniqueness in some workflows, but `customers.contact_number` and `services.name` are not database-unique.
- Orders and services are M:N only through `order_items`; no direct FK exists between `orders` and `services`.
- `payments` has no refund/void entity. There are no database tables for inventory, system settings, or stored report definitions.
- The schema has no declared CHECK constraints for monetary/order business rules; those rules are enforced in PHP.

## Validation report

| Check | Result |
|---|---|
| Live database inspected | `stacey_laundry_hub` on local MariaDB; read-only metadata queries |
| Confirmed tables | 15 |
| Confirmed declared foreign keys | 20 |
| Tables represented in editable ERD | 15 of 15 |
| FK relationships represented | 20 of 20 |
| Nullable FK columns | `activity_logs.user_id`, `orders.created_by`, `payments.created_by`, `customer_notifications.customer_id`, `customer_notifications.order_id`, `customer_support_messages.order_id`, `customer_support_messages.replied_by` |
| Tables with no outgoing FKs | `users`, `customers`, `services` |
| Database changes made | None |

### Migration/setup discrepancy

The live database includes the five tables introduced by `2026_11_19_customer_portal.sql`, but the root `setup.php` currently imports only `schema.sql` and `seed.sql`; a fresh setup therefore will not create the full live customer-portal schema. Also, `schema.sql` already includes `users.email` and `user_remember_tokens`, which are also added by `2026_11_17_login_email_and_remember_tokens.sql`. Applying that migration after the current schema would attempt to add existing objects. This ERD describes the confirmed live schema and does not apply or alter migrations.

# RAYHAAN MOBILE KAHUTA - Business POS

A PHP-based point-of-sale / business management system for direct sales (no installments). Used for tracking customers, khata (credit) sales, payments, inventory, and financial books.

**RAYHAAN MOBILE KAHUTA** | Mobile Phone Sales | Near Ameen Plaza, Kallar Road, Kahuta | Cell: 0336-5389945

## Features

- **Customer Management** - Registration, CNIC tracking, khata/credit balances, full history, ledger print
- **Sales & Billing** - Direct sale / khata invoicing with product/item tracking, cash received vs balance due
- **Credit (Khata) Sales** - Record full-credit, partial, or fully paid sales with running balances
- **Payment Collection** - Record cash/card/bank payments against invoices with receipts
- **Inventory Management** - Products (mobile, laptop, general), brands, categories, suppliers, purchases with IMEI/serial tracking
- **Cash Book** - Daily cash position with opening/closing balances, inflow/outflow tracking
- **Bank Book** - Multi-account bank transaction management with cheque tracking
- **General Ledger** - Party-based ledger entries
- **Expense Management** - Categories and expense recording
- **Reports** - Sales report, closing report with customer-wise balances

## Product Types

- Mobile (IMEI, storage, RAM tracking)
- Laptop (processor, RAM, storage, screen size tracking)
- General (other items)

## Tech Stack

- **Backend:** PHP 7.4+ (native, no framework)
- **Database:** MySQL 5.7+ / MariaDB
- **Frontend:** Bootstrap 4.6, jQuery, Font Awesome 5
- **Date Picker:** Flatpickr
- **Print:** Dedicated print-optimized layouts

## Installation

1. Copy the `rayhaan_mobile` folder to your web server directory (e.g., `htdocs`).

2. Import the database schema:
   ```bash
   mysql -u root < database_schema.sql
   ```

3. Import the admin seed data:
   ```bash
   mysql -u root rayhaan_mobile < seed_admin.sql
   ```

4. Configure database connection in `config/db.php`:
   ```php
   $host = 'localhost';
   $username = 'root';
   $password = '';
   $db_mode = 'client'; // 'client' for production, 'test' for development
   ```

5. Access the application at `http://localhost/rayhaan_mobile`

## Usage

### Login
Default admin credentials are seeded via `seed_admin.sql`. Login at `login.php`.

### Workflow
1. **Register customers** under Customer Management (optional for walk-in sales)
2. **Add products** under Inventory (choose Mobile / Laptop / General)
3. **Make a sale** - enter items, received amount (0 = full khata), payment method
4. **Collect payments** on khata invoices from the customer's Credit Received tab
5. **Track finances** via Cash Book, Bank Book, and reports

## Directory Structure

```
├── assets/              # CSS, JS
├── config/              # Database configuration
├── includes/            # Header, footer, auth, helper functions
├── modules/
│   ├── bankbook/        # Bank account & transaction management
│   ├── cashbook/        # Daily cash book
│   ├── customers/       # Customer CRUD, view (khata/ledger), history
│   ├── expenses/        # Expense categories & entries
│   ├── general/         # General ledger
│   ├── inventory/       # Products, purchases, suppliers
│   ├── payments/        # Payment receipt
│   ├── reports/         # Sales report, closing report
│   └── sales/           # New sale, invoices
├── database_schema.sql  # Full database schema
└── seed_admin.sql       # Admin user seed
```

## Database

Key tables:
- `customers`, `sales`, `sale_items`, `sale_returns`
- `payments`, `products`, `suppliers`, `purchases`, `product_serials`
- `cash_book_daily`, `cash_book`, `bank_accounts`, `bank_transactions`
- `chart_of_accounts`, `journal_entries`, `general_parties`, `general_transactions`

Sales track `paid_amount` / `due_amount` with payment status `paid` / `partial` / `pending` (no installment schedules or interest).

-- ============================================================
-- RAYHAAN MOBILE KAHUTA - BUSINESS POS
-- Database Schema (Direct Sale / Purchase - No Installments)
-- ============================================================

DROP DATABASE IF EXISTS rayhaan_mobile;
CREATE DATABASE rayhaan_mobile
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE rayhaan_mobile;

-- ---------------------------------------------------------
-- 1. branches
-- ---------------------------------------------------------
CREATE TABLE branches (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    address     TEXT,
    phone       VARCHAR(20),
    status      TINYINT(1) DEFAULT 1,
    created_at  DATE NOT NULL,
    updated_at  DATE DEFAULT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 2. users
-- ---------------------------------------------------------
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50) NOT NULL UNIQUE,
    password      VARCHAR(255) NOT NULL,
    full_name     VARCHAR(100) NOT NULL,
    email         VARCHAR(100),
    phone         VARCHAR(20),
    role          ENUM('admin','manager','cashier','salesperson','accountant') DEFAULT 'cashier',
    branch_id     INT DEFAULT NULL,
    status        TINYINT(1) DEFAULT 1,
    created_at    DATE NOT NULL,
    updated_at    DATE DEFAULT NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 3. activity_logs
-- ---------------------------------------------------------
CREATE TABLE activity_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT DEFAULT NULL,
    action      VARCHAR(100) NOT NULL,
    module      VARCHAR(50) NOT NULL,
    reference_id INT DEFAULT NULL,
    description TEXT,
    ip_address  VARCHAR(45),
    created_at  DATE NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 4. customers
-- ---------------------------------------------------------
CREATE TABLE customers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    customer_no     VARCHAR(20) NOT NULL UNIQUE,
    full_name       VARCHAR(100) NOT NULL,
    phone           VARCHAR(20) NOT NULL,
    email           VARCHAR(100),
    address         TEXT,
    city            VARCHAR(50),
    cnic            VARCHAR(15) DEFAULT NULL UNIQUE,
    cnic_expiry     DATE DEFAULT NULL,
    guardian_name   VARCHAR(100),
    guardian_relation VARCHAR(50),
    occupation      VARCHAR(100),
    monthly_income  DECIMAL(12,2) DEFAULT 0.00,
    opening_due     DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Previous dues before system',
    opening_paid    DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Amount already paid before system',
    notes           TEXT,
    branch_id       INT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 5. customer_documents
-- ---------------------------------------------------------
CREATE TABLE customer_documents (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    customer_id   INT NOT NULL,
    document_type ENUM('cnic','utility_bill','salary_slip','other') NOT NULL,
    file_path     VARCHAR(255) NOT NULL,
    uploaded_at   DATE NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 6. categories
-- ---------------------------------------------------------
CREATE TABLE categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description TEXT,
    product_type VARCHAR(50) DEFAULT 'general' COMMENT 'general, mobile, laptop',
    status      TINYINT(1) DEFAULT 1,
    created_at  DATE NOT NULL,
    updated_at  DATE DEFAULT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 7. brands
-- ---------------------------------------------------------
CREATE TABLE brands (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description TEXT,
    status      TINYINT(1) DEFAULT 1,
    created_at  DATE NOT NULL,
    updated_at  DATE DEFAULT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 8. suppliers
-- ---------------------------------------------------------
CREATE TABLE suppliers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    contact_person  VARCHAR(100),
    phone           VARCHAR(20),
    email           VARCHAR(100),
    address         TEXT,
    city            VARCHAR(50),
    opening_balance DECIMAL(12,2) DEFAULT 0.00,
    adjustment      DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Positive = we owe, Negative = supplier owes us',
    status          TINYINT(1) DEFAULT 1,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 9. products
-- ---------------------------------------------------------
CREATE TABLE products (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(50) NOT NULL UNIQUE COMMENT 'Item Code',
    name            VARCHAR(100) NOT NULL,
    description     TEXT,
    category_id     INT DEFAULT NULL,
    brand_id        INT DEFAULT NULL,
    supplier_id     INT DEFAULT NULL,
    color           VARCHAR(50) DEFAULT NULL,
    imei_no_1       VARCHAR(100) DEFAULT NULL COMMENT 'Mobile IMEI 1',
    imei_no_2       VARCHAR(100) DEFAULT NULL COMMENT 'Mobile IMEI 2 (dual SIM)',
    storage         VARCHAR(50) DEFAULT NULL COMMENT 'e.g. 64GB, 128GB',
    ram             VARCHAR(50) DEFAULT NULL COMMENT 'e.g. 4GB, 6GB, 8GB',
    processor       VARCHAR(100) DEFAULT NULL COMMENT 'e.g. Intel i5, Ryzen 5',
    screen_size     VARCHAR(20) DEFAULT NULL COMMENT 'e.g. 15.6", 14"',
    graphics        VARCHAR(100) DEFAULT NULL COMMENT 'e.g. NVIDIA GTX 1650, Integrated',
    warranty_months INT DEFAULT NULL,
    product_condition VARCHAR(50) DEFAULT 'New' COMMENT 'New, Used, Refurbished',
    purchase_price  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    sale_price      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    opening_stock   INT NOT NULL DEFAULT 0,
    stock_quantity  INT NOT NULL DEFAULT 0,
    min_stock_level INT DEFAULT 0 COMMENT 'Low stock alert threshold',
    unit            VARCHAR(20) DEFAULT 'pcs',
    product_type    VARCHAR(50) DEFAULT 'general' COMMENT 'general, mobile, laptop',
    has_serial      TINYINT(1) DEFAULT 0 COMMENT '1 = track serial/IMEI',
    status          TINYINT(1) DEFAULT 1,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 10. product_serials
-- ---------------------------------------------------------
CREATE TABLE product_serials (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    product_id      INT NOT NULL,
    serial_number   VARCHAR(100),
    purchase_price  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    imei_number     VARCHAR(100),
    purchase_id     INT DEFAULT NULL,
    sale_id         INT DEFAULT NULL,
    status          ENUM('available','sold','returned') DEFAULT 'available',
    notes           TEXT,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 11. purchases
-- ---------------------------------------------------------
CREATE TABLE purchases (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id     INT DEFAULT NULL,
    person_name     VARCHAR(100) DEFAULT NULL COMMENT 'Random person (no supplier)',
    person_phone    VARCHAR(20) DEFAULT NULL,
    person_cnic     VARCHAR(15) DEFAULT NULL,
    person_address  TEXT DEFAULT NULL,
    invoice_no      VARCHAR(50),
    purchase_date   DATE NOT NULL,
    total_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    paid_amount     DECIMAL(12,2) DEFAULT 0.00,
    due_amount      DECIMAL(12,2) DEFAULT 0.00,
    status          ENUM('pending','received','cancelled') DEFAULT 'pending',
    notes           TEXT,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 12. purchase_items
-- ---------------------------------------------------------
CREATE TABLE purchase_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id     INT NOT NULL,
    product_id      INT NOT NULL,
    quantity        INT NOT NULL DEFAULT 0,
    purchase_price  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    subtotal        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 13. discounts
-- ---------------------------------------------------------
CREATE TABLE discounts (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(100) NOT NULL,
    description         TEXT,
    discount_type       ENUM('percentage','fixed') NOT NULL,
    discount_value      DECIMAL(12,2) NOT NULL,
    start_date          DATE DEFAULT NULL,
    end_date            DATE DEFAULT NULL,
    min_purchase_amount DECIMAL(12,2) DEFAULT 0.00,
    status              TINYINT(1) DEFAULT 1,
    created_at          DATE NOT NULL,
    updated_at          DATE DEFAULT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 14. sales
-- ---------------------------------------------------------
CREATE TABLE sales (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no          VARCHAR(20) NOT NULL UNIQUE,
    customer_id         INT NOT NULL,
    sale_date           DATE NOT NULL,
    subtotal            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_id         INT DEFAULT NULL,
    discount_amount     DECIMAL(12,2) DEFAULT 0.00,
    taxable_amount      DECIMAL(12,2) DEFAULT 0.00,
    tax_rate            DECIMAL(5,2) DEFAULT 0.00,
    tax_amount          DECIMAL(12,2) DEFAULT 0.00,
    total_amount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    paid_amount         DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Amount received at sale time',
    due_amount          DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Remaining balance (credit/khata)',
    payment_method      ENUM('cash','card','bank_transfer','bank') DEFAULT 'cash',
    payment_status      ENUM('paid','partial','pending') DEFAULT 'pending' COMMENT 'pending = full credit, partial = partly paid, paid = cleared',
    status              ENUM('active','completed','cancelled') DEFAULT 'active',
    notes               TEXT,
    branch_id           INT DEFAULT NULL,
    created_by          INT DEFAULT NULL,
    created_at          DATE NOT NULL,
    updated_at          DATE DEFAULT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (discount_id) REFERENCES discounts(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 15. sale_items
-- ---------------------------------------------------------
CREATE TABLE sale_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    sale_id     INT NOT NULL,
    product_id  INT DEFAULT NULL,
    serial_id   INT DEFAULT NULL,
    item_description VARCHAR(255) DEFAULT NULL COMMENT 'Free text description',
    quantity    INT NOT NULL DEFAULT 1,
    price       DECIMAL(12,2) NOT NULL,
    subtotal    DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (serial_id) REFERENCES product_serials(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 16. sale_returns
-- ---------------------------------------------------------
CREATE TABLE sale_returns (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    sale_id         INT DEFAULT NULL,
    product_id      INT DEFAULT NULL,
    customer_id     INT NOT NULL,
    return_date     DATE NOT NULL,
    amount          DECIMAL(12,2) NOT NULL,
    quantity        INT NOT NULL DEFAULT 1,
    notes           TEXT,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 17. payments
-- ---------------------------------------------------------
CREATE TABLE payments (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    sale_id             INT DEFAULT NULL,
    payment_date        DATE NOT NULL,
    amount              DECIMAL(12,2) NOT NULL,
    payment_type        ENUM('sale','credit_payment','advance','partial') DEFAULT 'sale',
    payment_method      ENUM('cash','card','bank_transfer','bank') DEFAULT 'cash',
    reference_no        VARCHAR(50),
    notes               TEXT,
    branch_id           INT DEFAULT NULL,
    received_by         INT DEFAULT NULL,
    created_at          DATE NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 18. cash_book_daily
-- ---------------------------------------------------------
CREATE TABLE cash_book_daily (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    date            DATE NOT NULL UNIQUE,
    opening_balance DECIMAL(12,2) DEFAULT 0.00,
    total_inflow    DECIMAL(12,2) DEFAULT 0.00,
    total_outflow   DECIMAL(12,2) DEFAULT 0.00,
    closing_balance DECIMAL(12,2) DEFAULT 0.00,
    status          ENUM('open','closed') DEFAULT 'open',
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 19. cash_book
-- ---------------------------------------------------------
CREATE TABLE cash_book (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    daily_id        INT DEFAULT NULL,
    transaction_date DATE NOT NULL,
    transaction_type ENUM('opening_balance','inflow','outflow','closing_balance') NOT NULL,
    amount          DECIMAL(12,2) NOT NULL,
    description     TEXT,
    reference_type  VARCHAR(50) COMMENT 'e.g. sale, payment, expense',
    reference_id    INT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    FOREIGN KEY (daily_id) REFERENCES cash_book_daily(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 20. bank_accounts
-- ---------------------------------------------------------
CREATE TABLE bank_accounts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    account_name    VARCHAR(100) NOT NULL,
    bank_name       VARCHAR(100) NOT NULL,
    account_no      VARCHAR(50) NOT NULL UNIQUE,
    account_type    ENUM('current','savings','loan') DEFAULT 'current',
    branch_code     VARCHAR(50),
    opening_balance DECIMAL(12,2) DEFAULT 0.00,
    current_balance DECIMAL(12,2) DEFAULT 0.00,
    status          TINYINT(1) DEFAULT 1,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 21. bank_transactions
-- ---------------------------------------------------------
CREATE TABLE bank_transactions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    bank_account_id INT NOT NULL,
    transaction_date DATE NOT NULL,
    transaction_type ENUM('deposit','withdrawal','transfer_in','transfer_out') NOT NULL,
    amount          DECIMAL(12,2) NOT NULL,
    description     TEXT,
    reference_type  VARCHAR(50),
    reference_id    INT DEFAULT NULL,
    cheque_no       VARCHAR(50),
    cheque_date     DATE DEFAULT NULL,
    cheque_status   ENUM('pending','cleared','bounced','cancelled') DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 22. expense_categories
-- ---------------------------------------------------------
CREATE TABLE expense_categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description TEXT,
    status      TINYINT(1) DEFAULT 1,
    created_at  DATE NOT NULL,
    updated_at  DATE DEFAULT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 23. expenses
-- ---------------------------------------------------------
CREATE TABLE expenses (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    category_id     INT DEFAULT NULL,
    expense_date    DATE NOT NULL,
    amount          DECIMAL(12,2) NOT NULL,
    description     TEXT,
    vendor_name     VARCHAR(100),
    bill_no         VARCHAR(50),
    payment_method  ENUM('cash','card','bank_transfer','bank') DEFAULT 'cash',
    bank_account_id INT DEFAULT NULL,
    approval_status ENUM('pending','approved','rejected') DEFAULT 'pending',
    approved_by     INT DEFAULT NULL,
    notes           TEXT,
    branch_id       INT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL,
    FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 24. supplier_payments
-- ---------------------------------------------------------
CREATE TABLE supplier_payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id     INT NOT NULL,
    amount          DECIMAL(12,2) NOT NULL,
    payment_method  ENUM('cash','card','bank_transfer','bank') DEFAULT 'cash',
    bank_account_id INT DEFAULT NULL,
    description     TEXT,
    payment_date    DATE NOT NULL,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 25. chart_of_accounts
-- ---------------------------------------------------------
CREATE TABLE chart_of_accounts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    account_code    VARCHAR(20) NOT NULL UNIQUE,
    account_name    VARCHAR(100) NOT NULL,
    account_type    ENUM('asset','liability','equity','income','expense') NOT NULL,
    parent_id       INT DEFAULT NULL,
    description     TEXT,
    opening_balance DECIMAL(12,2) DEFAULT 0.00,
    current_balance DECIMAL(12,2) DEFAULT 0.00,
    status          TINYINT(1) DEFAULT 1,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL,
    FOREIGN KEY (parent_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 26. journal_entries
-- ---------------------------------------------------------
CREATE TABLE journal_entries (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    entry_date      DATE NOT NULL,
    reference_type  VARCHAR(50),
    reference_id    INT DEFAULT NULL,
    description     TEXT,
    total_debit     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_credit    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 27. journal_entry_items
-- ---------------------------------------------------------
CREATE TABLE journal_entry_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    journal_id      INT NOT NULL,
    account_id      INT NOT NULL,
    debit           DECIMAL(12,2) DEFAULT 0.00,
    credit          DECIMAL(12,2) DEFAULT 0.00,
    description     TEXT,
    FOREIGN KEY (journal_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 28. general_parties
-- ---------------------------------------------------------
CREATE TABLE general_parties (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    phone           VARCHAR(20),
    address         TEXT,
    opening_balance DECIMAL(12,2) DEFAULT 0.00,
    notes           TEXT,
    status          TINYINT(1) DEFAULT 1,
    created_at      DATE NOT NULL,
    updated_at      DATE DEFAULT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 29. general_transactions
-- ---------------------------------------------------------
CREATE TABLE general_transactions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    party_id        INT NOT NULL,
    transaction_date DATE NOT NULL,
    type            ENUM('receipt','payment') NOT NULL COMMENT 'receipt = money in, payment = money out',
    amount          DECIMAL(12,2) NOT NULL,
    payment_method  ENUM('cash','bank') DEFAULT 'cash',
    bank_account_id INT DEFAULT NULL,
    description     TEXT,
    created_by      INT DEFAULT NULL,
    created_at      DATE NOT NULL,
    FOREIGN KEY (party_id) REFERENCES general_parties(id) ON DELETE CASCADE,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- INDEXES
-- ============================================================
CREATE INDEX idx_customers_cnic ON customers(cnic);
CREATE INDEX idx_customers_phone ON customers(phone);
CREATE INDEX idx_products_code ON products(code);
CREATE INDEX idx_products_name ON products(name);
CREATE INDEX idx_sales_invoice ON sales(invoice_no);
CREATE INDEX idx_sales_customer ON sales(customer_id);
CREATE INDEX idx_sales_date ON sales(sale_date);
CREATE INDEX idx_payments_date ON payments(payment_date);
CREATE INDEX idx_expenses_date ON expenses(expense_date);
CREATE INDEX idx_cashbook_date ON cash_book(transaction_date);
CREATE INDEX idx_banktxn_date ON bank_transactions(transaction_date);
CREATE INDEX idx_journal_date ON journal_entries(entry_date);
CREATE INDEX idx_activity_user ON activity_logs(user_id);
CREATE INDEX idx_activity_module ON activity_logs(module);

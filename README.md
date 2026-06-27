<div align="center">

# Kids Berry
### Point of Sale & Inventory Management System

**A full-stack multi-branch retail management platform built for Kids Berry toy shop**
Developed by **Sky Tec**

<br>

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)
![Bootstrap](https://img.shields.io/badge/Bootstrap-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)
![HTML5](https://img.shields.io/badge/HTML5-E34F26?style=for-the-badge&logo=html5&logoColor=white)
![CSS3](https://img.shields.io/badge/CSS3-1572B6?style=for-the-badge&logo=css3&logoColor=white)


</div>

---

## Overview

**Kids Berry** is a real-world, production-deployed Point of Sale and Inventory Management System built for a toy retail shop operating across two physical branches in Sri Lanka. Developed end-to-end by **Sky Tec** as a client delivery, the system is currently live on Hostinger and handles all day-to-day retail operations including POS billing, inter-branch stock transfers, supplier management, customer tracking, AI-powered sales prediction, and SMS notifications.

> This repository contains the mock/demo version of the live system. Sensitive credentials and client-specific configurations have been removed.

**Two branch locations served:**

```
Branch 1  —  Jaffna Branch
Branch 2  —  Chunnakam Branch (Mega Centre)
```

---

## System Flow

```
                        [ index.php — Branch Selection ]
                                      |
              ________________________|________________________
             |                        |                        |
      [ Admin Portal ]        [ Branch 1 Portal ]    [ Branch 2 Portal ]
      admin/                  cashier/                branch_chunnakam/
      admin_login.php         caslogin.php            cashier/
             |                stock_keeper/           stock_keeper/
             |                stologin.php
             |
    .------------------.
    |  Central Control  |
    |  All Branches     |
    |  All Reports      |
    |  User Management  |
    '------------------'

       Branch 1 DB Tables          Branch 2 DB Tables
       ________________            ________________
       products                    products2
       customers                   customers2
       bills / bill_items          bills2 / bill_items2
       cashier_users               cashier_users2
       stock_keeper_users          stock_keeper_users2
       suppliers                   suppliers2
              \                          /
               \____stock_transactions__/
                     (shared transfer log)
```

---

## User Roles & Access

| Role | Access Level |
|---|---|
| **Admin** | Full system access, all branches, all reports, user management |
| **Cashier** | Billing, credit, returns, customer lookup, predictions |
| **Stock Keeper** | Stock, products, suppliers, barcode, transactions |
| **Branch Cashier** | Branch-specific billing (Chunnakam branch only) |

---

## Key Features

### Login & Branch Selection

<img width="1920" height="912" alt="image" src="https://github.com/user-attachments/assets/3a286533-035f-4963-ac48-473679bcddef" />


The entry point is a unified branch selection page with an animated floating toys background (deep purple to forest green gradient). Each branch routes to its own login portal, and each role (Admin, Cashier, Stock Keeper) has a separate authentication session managed via PHP `$_SESSION`.

```
User visits index.php
       |
       |-- Selects Branch 1  -->  cashier/caslogin.php  or  stock_keeper/stologin.php
       |
       |-- Selects Branch 2  -->  branch_chunnakam/index.php
       |
       |-- Admin             -->  admin/admin_login.php
```

---

### POS Billing System — Branch 1

<img width="1920" height="2107" alt="image" src="https://github.com/user-attachments/assets/99298159-8207-4da5-800b-b79e0ee189a7" />


The billing interface is the primary daily-use screen for cashiers:

- Live product search with instant dropdown during the billing session
- Auto-generated bill numbers using branch prefix format: `PS-B1-0001`
- Supports cash and credit payment methods
- Item-level discount application
- Customer linking via NIC number or phone number search
- Bills are printable and downloadable as PDF
- Bill history with full itemized view per transaction

```
Cashier logs in
      |
      +--> Search product (live AJAX)
      |         |
      |         +--> Add to bill + set quantity + apply discount
      |
      +--> Link customer (by NIC / phone)
      |
      +--> Choose payment method (Cash / Credit)
      |
      +--> Generate Bill  -->  Auto bill number assigned  -->  Print / Download
```

---

### Inter-Branch Stock Transfer — Branch 2 (Chunnakam) Stock Keeper

> **Access:** This feature is available exclusively from the **Branch 2 (Chunnakam Mega Centre)** Stock Keeper portal. Branch 1 stock keepers can initiate single-product transfers; Branch 2 supports full multi-product batch transfers.

This is the core feature connecting both branches. Stock keepers can move inventory between Branch 1 and Branch 2 in real time without manual duplication.

**How it works:**

```
Stock Keeper selects products to transfer
           |
           +--> Search by name / barcode / product ID (live search)
           |
           +--> Add to transfer queue (multiple products supported)
           |
           +--> Set quantity per product
           |
           +--> Choose: From Branch  -->  To Branch
           |
           +--> Submit Transfer
                    |
                    +--> System validates stock in source branch
                    |
                    +--> MySQL BEGIN TRANSACTION
                    |         |
                    |         +--> Deduct from source table (products / products2)
                    |         |
                    |         +--> Add to destination table (products2 / products)
                    |         |       (if product not present, auto-insert with same details)
                    |         |
                    |         +--> Log to stock_transactions table
                    |         |
                    |         +--> COMMIT  (or ROLLBACK on any failure)
                    |
                    +--> Transfer complete — both branches updated instantly
```

**Key behaviours:**
- If the product does not exist in the destination branch, it is automatically created with the transferred quantity
- If insufficient stock exists at source, transfer is blocked before any change is made
- Every transfer is logged with: product, quantity, from-branch, to-branch, stock keeper name, timestamp
- Chunnakam branch supports batch mode — multiple products queued and transferred in a single atomic operation

---

### Admin Dashboard

The central admin panel gives full visibility across both branches from a single interface:

- Real-time branch switching via AJAX (no page reload)
- Today's total sales, today's profit, total stock value
- Low stock alerts (products below 10 units)
- Sales trend chart (last 7 days) powered by Chart.js
- Recent activity feed
- Access to all management modules: Products, Customers, Cashiers, Stock Keepers, Suppliers, Sales, Reports

```
Admin Dashboard
      |
      +--> Switch Branch (Branch 1 / Branch 2)  -- AJAX, instant
      |
      +--> Today's Sales | Today's Profit | Stock Value  (KPI cards)
      |
      +--> Sales Trend Chart (7 days)
      |
      +--> Low Stock Alert List
      |
      +--> AI Cashier Prediction Panel
      |
      +--> Manage: Products / Customers / Cashiers / Stock Keepers / Suppliers
```

---

### AI-Powered Sales Prediction

A custom prediction engine runs in PHP using MySQL historical data:

- Analyses sales data from the last 4 weeks for the same day of week
- Applies growth factor, seasonality factor, and day-of-week weighting
- Predicts both per-cashier expected sales and overall branch total
- Available to Admin (both branches) and to each Cashier on their own dashboard
- No external ML library — fully built in PHP with weighted average algorithm

---

### Credit & Payment Tracking

- Full credit bill management with balance tracking
- Outstanding credit reports per customer
- Partial payment recording and running balance calculation

---

### Returns & Refund Management

- Return sale workflow with original bill reference
- Stock auto-adjustment on return
- Return record tracking via `returns_tracking` database table

---

### PSN (Product Serial Number) Manager

The PSN Manager is a serial number tracking system built for high-value or individually tracked products:

- Bulk PSN number generation using a configurable prefix, suffix, and numeric range (e.g., `KB-000001-A` to `KB-000500-A`)
- Each PSN is stored in `product_psn_tracking` with status tracking (`available` / `sold`)
- PSNs are linked to specific products and are marked as sold when billed
- Prevents duplicate PSN assignment across the system
- Available from both the Jaffna and Chunnakam cashier portals

---

### Inventory & Stock Management

- Product catalog with stock levels, sale price, and original price
- Barcode scanning and product lookup by barcode or product ID
- Low stock alerts on admin dashboard (threshold: 10 units)
- Full stock transaction log covering transfers, additions, and movements
- Product image uploads supported per branch

---

### Supplier Management

- Full supplier profile with contact information
- Supplier-linked products
- Purchase records and transaction history per supplier

---

### SMS Notification System

- Integrated with **Dialog Ideamart API** (Sri Lankan telco)
- Auto-formats Sri Lankan phone numbers to international `94XXXXXXXXX` format
- SMS logs stored in `sms_logs` database table
- Supports GSM7 encoding (English and Sinhala)
- Used for customer notifications and bill confirmations

---

### Reports & Analytics

- Daily, weekly, and monthly sales reports per branch
- Profit calculation (sale price minus original cost)
- Today's sales, today's profit, and total stock value on dashboard
- Cashier-wise performance breakdown
- Exportable / printable stock reports

---

## Project Structure

```
Kids Berry/
|
|-- index.php                         # Branch selection landing page
|-- index1.php                        # Alternate entry
|-- send_sms.php                      # Dialog Ideamart SMS handler
|-- kidsberry.sql                     # Full MySQL database (20 tables)
|-- .gitignore
|
|-- admin/
|   |-- admin_dashboard.php           # Multi-branch analytics + KPIs
|   |-- admin_login.php               # Admin authentication
|   |-- admin_register.php            # Admin account creation
|   |-- product_manage.php            # Central product catalog
|   |-- customer_manage.php           # Customer records (all branches)
|   |-- cashier_manage.php            # Cashier accounts
|   |-- stockkeeper_manage.php        # Stock keeper accounts
|   |-- suppliers_manage.php          # Supplier management
|   |-- sales_manage.php              # Sales overview
|   |-- report_show.php               # Reports
|   |-- cashier_prediction.php        # AI prediction per cashier
|   |-- admin_contact_management.php  # Support contact system
|   |-- send_sms.php                  # Bulk SMS trigger
|   |-- changepassword.php            # Password change
|
|-- cashier/                          # Branch 1 — Cashier Portal
|   |-- billing.php                   # Main POS billing screen
|   |-- bill.php                      # Bill view / receipt
|   |-- bill_history.php              # Transaction history
|   |-- bill_management.php           # Bill operations
|   |-- credit_payments.php           # Credit tracking
|   |-- customer.php                  # Customer management
|   |-- return_sale.php               # Returns & refunds
|   |-- prediction_dashboard.php      # AI prediction for cashier
|   |-- psn_manager.php               # Product serial number manager
|   |-- report.php                    # Branch reports
|   |-- print_bill.php                # Bill print view
|   |-- download_bill.php             # Bill PDF download
|   |-- caslogin.php                  # Cashier login
|
|-- stock_keeper/                     # Branch 1 — Stock Keeper Portal
|   |-- dashboard.php                 # Stock overview
|   |-- product.php                   # Product catalog + stock levels
|   |-- barcode.php                   # Barcode scanner / lookup
|   |-- suppliers.php                 # Supplier records
|   |-- stock_transactions1.php       # Stock transfer + transaction log
|   |-- customershow.php              # Customer listing
|   |-- report_keep.php               # Stock reports
|   |-- stologin.php                  # Stock keeper login
|
|-- branch_chunnakam/                 # Branch 2 — Chunnakam Mega Centre
|   |-- index.php                     # Branch 2 login entry
|   |-- cashier/                      # Branch 2 Cashier Portal (mirrors Branch 1)
|   |-- stock_keeper/                 # Branch 2 Stock Keeper Portal
|       |-- stock_transactions.php    # Batch stock transfer (multi-product)
|
|-- assets/
    |-- img/
        |-- logo.jpg
```

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 7.4+ (procedural with session-based auth) |
| Database | MySQL via MySQLi extension |
| Frontend | HTML5, CSS3, JavaScript (vanilla + AJAX) |
| UI Framework | Bootstrap + Font Awesome + Google Fonts (Inter, Outfit) |
| Charts | Chart.js (sales trend charts on dashboard) |
| SMS API | Dialog Ideamart REST API |
| Hosting | Hostinger (live production deployment) |
| Theme | Deep purple / forest green custom UI with animated backgrounds |

---

## Database Schema

The database `kidsberry` contains 20 tables, designed with branch isolation in mind. All core tables have a `branch1` and `branch2` version:

| Group | Tables |
|---|---|
| Users | `admin_users`, `cashier`, `cashier2`, `cashier_users`, `cashier_users2`, `stock_keeper_users`, `stock_keeper_users2` |
| Products | `products` (Branch 1), `products2` (Branch 2) |
| Customers | `customers` (Branch 1), `customers2` (Branch 2) |
| Bills | `bills`, `bill_items` (Branch 1), `bills2`, `bill_items2` (Branch 2) |
| Suppliers | `suppliers` (Branch 1), `suppliers2` (Branch 2) |
| Stock | `stock_transactions` (shared transfer log), `returns_tracking`, `returns_tracking2`, `product_psn_tracking` |
| Logs | `sms_logs`, `admin_contact_requests` |

---

## Local Setup Guide

### Prerequisites

- PHP 7.4 or higher
- MySQL 5.7 or higher
- XAMPP / WAMP / LAMP or any local PHP server

### Installation Steps

**1. Clone the repository**
```bash
git clone https://github.com/your-username/kids-berry.git
```

**2. Move to server root**

Copy the `Kids Berry` folder into your XAMPP/WAMP `htdocs` or `www` directory.

**3. Import the database**

Open phpMyAdmin, create a new database named `kidsberry`, then import:
```
Kids Berry/kidsberry.sql
```

**4. Configure database connection**

The default credentials are set to local XAMPP defaults across all PHP files:
```php
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "kidsberry";
```

Update these in each PHP file if your local environment differs.

**5. Configure SMS API (optional)**

In `send_sms.php` and `admin/send_sms.php`, replace the placeholder credentials:
```php
$applicationId = "APP_XXXXXX";   // Your Dialog Ideamart App ID
$password      = "your_password"; // Your Dialog Ideamart password
```

**6. Launch the system**

Navigate to:
```
http://localhost/Kids Berry/index.php
```

Select your branch and log in using the appropriate role credentials.

---

## Deployment Notes

This system is deployed and running live on **Hostinger** for Kids Berry as a production client project.

For production deployment:
- Update all database credentials in every PHP file to match your hosting database
- Upload the full project via FTP or Hostinger File Manager
- Import `kidsberry.sql` via phpMyAdmin on your hosting panel
- Set file permissions for upload directories (`/stock_keeper/uploads/`, `/admin/uploads/`) to `755`
- Configure Dialog Ideamart SMS credentials in `send_sms.php`

---

## Developer

<div align="center">

**Developed by [Sky Tec](https://sky-tec.site/)**

This is a client-commissioned project built for Kids Berry toy retail.
The repository reflects the mock/demo version of the live system.
Sensitive credentials and client-specific configurations have been removed.

<br>

Built with dedication by **Sky Tec** for **Kids Berry**

</div>

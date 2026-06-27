<div align="center">

# Kids Berry
### Velvet Vogue — Point of Sale & Inventory Management System

**A full-stack multi-branch retail management platform built for Kids Berry toy shop**
Developed by **Sky Tec** &nbsp;|&nbsp; Live on **Hostinger**

<br>

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)
![Bootstrap](https://img.shields.io/badge/Bootstrap-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)
![HTML5](https://img.shields.io/badge/HTML5-E34F26?style=for-the-badge&logo=html5&logoColor=white)
![CSS3](https://img.shields.io/badge/CSS3-1572B6?style=for-the-badge&logo=css3&logoColor=white)

<br>

![Status](https://img.shields.io/badge/Status-Live%20on%20Hostinger-4ade80?style=flat-square)
![Branches](https://img.shields.io/badge/Branches-2%20Locations-7e22ce?style=flat-square)
![Roles](https://img.shields.io/badge/User%20Roles-4-2e1065?style=flat-square)
![DB Tables](https://img.shields.io/badge/DB%20Tables-20-1e3a8a?style=flat-square)

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

The system has four distinct roles, each with a dedicated login portal and scoped access:

```
+------------------+-------------------------------------------------------+
|   ROLE           |   ACCESS                                              |
+------------------+-------------------------------------------------------+
|  Admin           |  Full system — all branches, reports, user mgmt,      |
|                  |  AI predictions, SMS, supplier & product control       |
+------------------+-------------------------------------------------------+
|  Cashier         |  POS billing, credit tracking, returns, customer       |
|  (Branch 1)      |  management, PSN manager, prediction dashboard,        |
|                  |  bill history, print & download bills                  |
+------------------+-------------------------------------------------------+
|  Stock Keeper    |  Stock management, barcode lookup, inter-branch        |
|  (Branch 1)      |  stock transfer (initiate), supplier records,          |
|                  |  product catalog, stock transaction log                |
+------------------+-------------------------------------------------------+
|  Branch 2        |  Same as Branch 1 roles above but scoped to           |
|  (Cashier +      |  Chunnakam Mega Centre data — with additional          |
|  Stock Keeper)   |  multi-product batch stock transfer capability         |
+------------------+-------------------------------------------------------+
```

---

## Key Features

### Login & Branch Selection

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

The billing interface (`cashier/billing.php`) is the primary daily-use screen for cashiers:

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

### Additional Features

**Credit & Payment Tracking**
Cashiers can record credit sales and track outstanding balances per customer. Partial payments are supported with a running balance updated on each payment. Credit reports show all open balances.

**Returns & Refund Management**
Return sales are processed with reference to the original bill. Stock is automatically adjusted upward on return. All returns are logged in `returns_tracking` / `returns_tracking2`.

**PSN (Product Serial Number) Manager**
For individually tracked products, cashiers can bulk-generate serial numbers with a custom prefix, suffix, and number range (e.g. `KB-000001-A`). Each PSN is stored in `product_psn_tracking` and marked `sold` when billed. Duplicates are prevented system-wide.

**Barcode Scanner**
Stock keepers can look up products by scanning or typing a barcode. Results show product name, current stock, price, and supplier. Supports both barcode field and product ID as lookup keys.

**SMS Notifications**
Integrated with Dialog Ideamart REST API. Sri Lankan phone numbers are auto-formatted to `94XXXXXXXXX`. SMS logs are stored in `sms_logs`. Supports GSM7 encoding for English and Sinhala.

**Supplier Management**
Full supplier profiles with contact details, linked products, and purchase history per supplier — available per branch.

**Reports & Analytics**
Daily, weekly, and monthly sales reports per branch. Profit = sale price minus original cost. Cashier-wise performance breakdown. All reports are printable.

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
| Backend | PHP 7.4+ — procedural, session-based authentication |
| Database | MySQL via MySQLi — 20 tables, branch-isolated design |
| Frontend | HTML5, CSS3, Vanilla JavaScript, AJAX |
| UI Framework | Bootstrap, Font Awesome 6, Google Fonts (Inter, Outfit) |
| Charts | Chart.js — sales trend graphs on dashboard |
| SMS | Dialog Ideamart REST API |
| Hosting | Hostinger — live production deployment |
| Theme | Deep purple / forest green gradient, animated floating background |

---

## Database Schema

`kidsberry` — 20 tables. All core tables exist in pairs for branch isolation:

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

## Local Setup

**Prerequisites:** PHP 7.4+, MySQL 5.7+, XAMPP / WAMP / LAMP

```bash
# 1. Clone the repository
git clone https://github.com/your-username/kids-berry.git

# 2. Move into your server root (htdocs or www)
# 3. Import the database
#    Open phpMyAdmin > create database 'kidsberry' > import kidsberry.sql

# 4. Default DB credentials (update if needed)
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "kidsberry";

# 5. Optional — configure SMS
#    In send_sms.php: replace APP_XXXXXX and your_password with Dialog Ideamart credentials

# 6. Launch
http://localhost/Kids Berry/index.php
```

---

## Deployment Notes

Live on **Hostinger** as a production client system.

For deployment:
- Update DB credentials across all PHP files to match hosting environment
- Upload via FTP or Hostinger File Manager
- Import `kidsberry.sql` via phpMyAdmin on hosting panel
- Set upload directories to permission `755`: `/stock_keeper/uploads/`, `/admin/uploads/`
- Add Dialog Ideamart credentials in `send_sms.php`

---

## Developer

<div align="center">

**Developed by Sky Tec**

Client-commissioned project for **Kids Berry** toy retail, Sri Lanka.
This repository is the demo version. Live system hosted on Hostinger.

</div>

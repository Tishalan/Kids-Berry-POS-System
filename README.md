<div align="center">

# Kids Berry
### Velvet Vogue Point of Sale & Inventory Management System

**A full-stack multi-branch retail management platform built for Kids Berry toy shop**
Developed by **Sky Tec** | Hosted on **Hostinger**

---

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![HTML5](https://img.shields.io/badge/HTML5-E34F26?style=for-the-badge&logo=html5&logoColor=white)
![CSS3](https://img.shields.io/badge/CSS3-1572B6?style=for-the-badge&logo=css3&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)
![Bootstrap](https://img.shields.io/badge/Bootstrap-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)

</div>

---

## Overview

**Kids Berry** is a real-world, production-deployed Point of Sale and Inventory Management System built specifically for Kids Berry, a toy retail shop operating across two branches in Sri Lanka. The system is live on Hostinger and handles day-to-day retail operations including billing, stock management, customer tracking, supplier management, and AI-powered sales prediction.

This project was developed end-to-end by **Sky Tec** as a client delivery, serving two physical branch locations:
- **Branch 1** - Jaffna Branch
- **Branch 2** - Chunnakam Branch (Mega Centre)

---

## System Architecture

```
Kids Berry/
|
|-- index.php                    # Branch selection landing page
|-- index1.php                   # Alternate login entry
|-- send_sms.php                 # Dialog Ideamart SMS API handler
|-- kidsberry.sql                # Full MySQL database schema
|-- .gitignore
|
|-- admin/                       # Central Admin Control Panel
|   |-- admin_dashboard.php      # Multi-branch analytics dashboard
|   |-- admin_login.php          # Admin authentication
|   |-- admin_register.php       # Admin account management
|   |-- product_manage.php       # Central product catalog
|   |-- customer_manage.php      # Customer records across branches
|   |-- cashier_manage.php       # Cashier account management
|   |-- stockkeeper_manage.php   # Stock keeper management
|   |-- suppliers_manage.php     # Supplier management
|   |-- sales_manage.php         # Sales overview (all branches)
|   |-- report_show.php          # Advanced reporting
|   |-- cashier_prediction.php   # AI-based cashier sales prediction
|   |-- admin_contact_management.php  # Support contact system
|   |-- send_sms.php             # Bulk SMS trigger
|   |-- changepassword.php       # Password management
|
|-- cashier/                     # Jaffna Branch - Cashier Portal
|   |-- billing.php              # POS billing interface
|   |-- bill.php                 # Bill view / receipt
|   |-- bill_history.php         # Sales history
|   |-- bill_management.php      # Bill operations
|   |-- credit_payments.php      # Credit tracking & payment collection
|   |-- customer.php             # Customer management
|   |-- return_sale.php          # Returns & refund handling
|   |-- prediction_dashboard.php # AI sales prediction for cashiers
|   |-- report.php               # Branch sales reports
|   |-- psn_manager.php          # PSN/session management
|   |-- print_bill.php           # Bill print view
|   |-- download_bill.php        # Bill PDF download
|   |-- caslogin.php             # Cashier login
|
|-- stock_keeper/                # Jaffna Branch - Stock Keeper Portal
|   |-- dashboard.php            # Stock overview dashboard
|   |-- product.php              # Product catalog & stock levels
|   |-- barcode.php              # Barcode scanning / lookup
|   |-- suppliers.php            # Supplier records & contacts
|   |-- stock_transactions1.php  # Incoming/outgoing stock logs
|   |-- customershow.php         # Customer listing for stock keeper
|   |-- report_keep.php          # Stock-level reports
|   |-- stologin.php             # Stock keeper login
|
|-- branch_chunnakam/            # Chunnakam Branch (Mega Centre)
|   |-- index.php                # Branch login page
|   |-- cashier/                 # Mirror of Jaffna cashier portal
|   |-- stock_keeper/            # Mirror of Jaffna stock keeper portal
|
|-- assets/
    |-- img/
        |-- logo.jpg             # Kids Berry logo
```

---

## Key Features

### Multi-Branch Management
The system operates across two fully independent branches, each with their own:
- Separate database tables (`branch1` and `branch2` table sets)
- Independent cashier and stock keeper login portals
- Branch-specific billing, stock, customer, and supplier records
- Admin can switch between branches from the central dashboard in real time via AJAX

### Point of Sale (POS) Billing System
- Live product search during billing session
- Auto-generated bill numbers with branch prefix (e.g., `PS-B1-0001`)
- Multiple payment method support including cash and credit
- Discount application at item level
- Bill print and PDF download
- Customer-linked billing with NIC / phone verification

### Credit & Payment Tracking
- Full credit bill management with balance tracking
- Outstanding credit reports per customer
- Partial payment recording and running balance calculation

### Returns & Refund Management
- Return sale workflow with original bill reference
- Stock auto-adjustment on return
- Return record tracking via `returns_tracking` database table

### Inter-Branch Stock Transfer
One of the core features connecting both branches is the **real-time stock transfer system**. Stock keepers at either branch can move inventory directly between Branch 1 (Jaffna) and Branch 2 (Chunnakam Mega Centre) without any manual data entry duplication.

How it works:
- Stock keeper selects a product by name, barcode, or product ID with a live search
- Chooses source branch and destination branch, and enters quantity
- System validates available stock in the source branch before proceeding
- On confirmation, the source branch table (`products` or `products2`) is decremented and the destination branch table is incremented in a single **MySQL transaction** (atomic operation with rollback on failure)
- If the product does not yet exist in the destination branch, it is automatically inserted with the same product details and the transferred quantity as initial stock
- Every transfer is logged in the `stock_transactions` table with timestamp, product, quantity, from-branch, to-branch, and the stock keeper who initiated it

The Chunnakam branch additionally supports **multi-product batch transfers**, allowing a stock keeper to queue multiple products and transfer them all in one operation, with each product validated and processed within a single transaction.

### Inventory & Stock Management
- Product catalog with stock levels, sale price, and original price
- Barcode scanning and product lookup by barcode or product ID
- Low stock alerts on admin dashboard (threshold: 10 units)
- Full stock transaction log covering transfers, additions, and movements
- Product image uploads supported per branch

### PSN (Product Serial Number) Manager
The PSN Manager is a serial number tracking system built for high-value or individually tracked products:
- Bulk PSN number generation using a configurable prefix, suffix, and numeric range (e.g., `KB-000001-A` to `KB-000500-A`)
- Each PSN is stored in `product_psn_tracking` with status tracking (`available` / `sold`)
- PSNs are linked to specific products and are marked as sold when billed
- Prevents duplicate PSN assignment across the system
- Available from both the Jaffna and Chunnakam cashier portals

### Supplier Management
- Full supplier profile with contact information
- Supplier-linked products
- Purchase records and transaction history per supplier

### Customer Management
- Customer registration with NIC, phone number, and address
- Purchase history per customer
- Customer search across name, phone, and NIC

### AI-Powered Sales Prediction
A custom prediction engine is built into both the admin and cashier dashboards:
- Uses historical sales data from the last 4 weeks (same day of week)
- Applies growth factor, seasonality factor, and day-of-week weighting
- Predicts per-cashier performance alongside branch-level totals
- Designed as a lightweight ML-style algorithm running entirely in PHP with MySQL data

### SMS Notification System
- Integrated with **Dialog Ideamart API** (Sri Lankan telco)
- Auto-formats Sri Lankan phone numbers to international `94XXXXXXXXX` format
- SMS logs stored in `sms_logs` database table
- Supports GSM7 encoding (English and Sinhala)
- Used for customer notifications and bill confirmations

### Reports & Analytics
- Daily, weekly, and monthly sales reports per branch
- Profit calculation (sale price minus original cost)
- Today's sales, today's profit, and total stock value on dashboard
- Cashier-wise performance breakdown
- Exportable / printable stock reports

### Role-Based Access Control
The system has four distinct user roles with separate login portals:

| Role | Access Level |
|---|---|
| **Admin** | Full system access, all branches, all reports, user management |
| **Cashier** | Billing, credit, returns, customer lookup, predictions |
| **Stock Keeper** | Stock, products, suppliers, barcode, transactions |
| **Branch Cashier** | Branch-specific billing (Chunnakam branch only) |

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

| Table Group | Tables |
|---|---|
| Users | `admin_users`, `cashier`, `cashier2`, `cashier_users`, `cashier_users2`, `stock_keeper_users`, `stock_keeper_users2` |
| Products | `products`, `products2` |
| Customers | `customers`, `customers2` |
| Bills | `bills`, `bills2`, `bill_items`, `bill_items2` |
| Suppliers | `suppliers`, `suppliers2` |
| Stock | `stock_transactions`, `returns_tracking`, `returns_tracking2`, `product_psn_tracking` |
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

## Screenshots

> Screenshots are referenced from the live hosted system. Add your own screenshots in a `/screenshots` folder and update these paths.

| Screen | Description |
|---|---|
| `screenshots/branch-select.png` | Landing page - branch selection with animated background |
| `screenshots/admin-dashboard.png` | Admin analytics dashboard with sales charts and branch switcher |
| `screenshots/billing.png` | POS billing interface with live product search |
| `screenshots/prediction.png` | AI sales prediction dashboard (cashier + branch level) |
| `screenshots/stock-dashboard.png` | Stock keeper dashboard with low stock alerts |
| `screenshots/stock-transfer.png` | Inter-branch stock transfer interface |
| `screenshots/barcode.png` | Barcode scanner / product lookup |
| `screenshots/psn-manager.png` | PSN bulk generation and serial tracking |
| `screenshots/credit-payments.png` | Credit bill management and payment tracking |
| `screenshots/report.png` | Sales and profit reports |

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

**Developed by Sky Tec**

This is a client-commissioned project built for Kids Berry toy retail. The repository reflects the mock/demo version of the live system. Sensitive credentials and client-specific configurations have been removed.

---

<div align="center">

Built with dedication by **Sky Tec** for **Kids Berry**

</div>

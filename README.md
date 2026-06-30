# SynergyERP

SynergyERP is a PHP and MySQL ERP web application for sales, purchasing,
inventory, warehouse movement, accounting, HR, manufacturing, reporting, and
role-based access control. The UI is built with Bootstrap, jQuery DataTables,
server-side table APIs, and legacy-compatible module mappings.

คำบรรยาย: ระบบ ERP สำหรับบริหารงานขาย ซื้อ สต็อก คลังสินค้า บัญชี บุคคล
การผลิต รายงาน และสิทธิ์ผู้ใช้ โดยย้าย logic จากระบบ stock เดิมมาเป็นเว็บ
PHP/MySQL ที่ใช้งานผ่าน XAMPP ได้

## Public Edition and Commercial Services

This public repository is the free ERP baseline. It is meant to show real
working code, database structure, documentation, and operational coverage before
selling paid implementation work.

Commercial work should be sold around:

- installation and server deployment
- data migration from Excel, old stock systems, or accounting exports
- custom modules for each business
- monthly maintenance, backup, and support
- RAG AI assistant for ERP manuals, reports, documents, and read-only business
  data

See:

- `docs/PUBLIC_EDITION.md`
- `docs/SERVICE_OFFER.md`
- `docs/RAG_AI_BLUEPRINT.md`
- `ai/README.md`

## Stack

- PHP 8+
- MySQL or MariaDB
- Bootstrap 5
- jQuery DataTables + Buttons
- XAMPP-friendly project layout

## Project Path

- Project root: `C:\xampp\htdocs\synergyerp`
- Local URL: `http://localhost/synergyerp`
- Main API: `http://localhost/synergyerp/api/table.php`

If Apache is configured for another port, add that port to the URL, for example
`http://localhost:888/synergyerp`.

## Database

Database dumps are included in `database/`:

- `database/synergyerp_simple.sql` - recommended restore file
- `database/synergyerp_full.sql` - full archive from the existing project

Create a database and import the recommended dump:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS synergyerp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
Get-Content -Path C:\xampp\htdocs\synergyerp\database\synergyerp_simple.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root synergyerp
```

Local credentials should be stored in `config/app.local.php`. This file is
ignored by Git. If it is not present, `config/app.php` reads these environment
variables:

- `SYNERGYERP_DB_HOST`
- `SYNERGYERP_DB_PORT`
- `SYNERGYERP_DB_DATABASE`
- `SYNERGYERP_DB_USERNAME`
- `SYNERGYERP_DB_PASSWORD`
- `SYNERGYERP_DB_CHARSET`

Default sample login from the imported database:

- Username: `222`
- Password: `222`

## Main Features

- Dynamic module/form generation from the database schema
- Server-side DataTables for pagination, filtering, sorting, and export
- Generic CRUD for mapped business tables
- Role and department access management
- Sales, purchasing, creditor/debtor, inventory, and warehouse workflows
- Barcode in/out screen for product movement
- GL, tax, HR attendance, leave, and payroll reports
- Manufacturing dashboard, BOM/routing, production lot, QC, and costing reports
- ERP flow page for end-to-end business process tracking
- HTML/PDF operational documentation in `docs/`

## Important Files

- `index.php` - main web entry
- `bootstrap.php` - service wiring and config loading
- `config/legacy_modules.php` - legacy ERP menu/module mapping
- `config/gl_hr_modules.php` - accounting and HR module mapping
- `config/manufacturing_modules.php` - manufacturing module mapping
- `api/table.php` - generic DataTables/CRUD endpoint
- `api/process.php` - master-detail process endpoint
- `api/business.php` - GL/HR report endpoint
- `api/erp.php` - ERP flow endpoint
- `scripts/` - migrations, smoke tests, and manual builders
- `database/` - SQL dumps for restore

## Optional Migrations

Run these only when updating an older database:

```powershell
Get-Content -Path C:\xampp\htdocs\synergyerp\scripts\migrate_inventory_location.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root synergyerp
Get-Content -Path C:\xampp\htdocs\synergyerp\scripts\migrate_gl_hr.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root synergyerp
Get-Content -Path C:\xampp\htdocs\synergyerp\scripts\migrate_mfg_suite.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root synergyerp
```

## Smoke Tests

```powershell
powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\synergyerp\scripts\smoke_test_stock2.ps1 -RootUrl "http://localhost/synergyerp/"
powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\synergyerp\scripts\gl_hr_smoke_test.ps1 -RootUrl "http://localhost/synergyerp/"
powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\synergyerp\scripts\mfg_suite_smoke_test.ps1 -RootUrl "http://localhost/synergyerp/"
```

## Notes

This repository includes application code, documentation, scripts, and database
dumps. Real production database credentials should stay in `config/app.local.php`
or environment variables, not in Git.

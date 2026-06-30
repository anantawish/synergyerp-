# SynergyERP Public Edition

## Purpose

SynergyERP Public Edition is the free ERP baseline used to demonstrate the
system, earn trust, and open the door for paid customization and AI work.

The repository can be public, but it must stay demo-safe.

## What Is Included

- PHP and MySQL ERP application
- sales, purchasing, inventory, warehouse, GL, HR, manufacturing, and reporting
  modules
- role and department access management
- database dumps for demo/restore
- operational manuals and test evidence
- migration and smoke-test scripts

## What Is Not Included

- production client credentials
- private deployment secrets
- client-specific business rules
- client-specific document templates
- managed backup and monitoring setup
- production RAG AI knowledge bases

## Public Safety Rules

- Keep `config/app.local.php` out of Git.
- Use demo-safe SQL dumps only.
- Do not commit client exports, invoices, payslips, ID files, or private
  contracts.
- Keep production customization in private branches or private repos.
- Use public documentation to sell implementation, not to expose client data.

## Conversion Path

1. Prospect reviews or installs the public ERP.
2. We map their workflow: sales, purchase, stock, warehouse, accounting, HR, and
   manufacturing.
3. We deploy a private client instance.
4. We migrate data and configure roles.
5. We add custom modules, reports, and document templates.
6. We add RAG AI for manuals, reports, SOPs, and permission-aware database
   questions.

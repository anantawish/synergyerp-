# Legacy Logic Mapping (stock -> stock2)

This document maps core logic entities from the legacy desktop system to the new web system.

## Sales

- `bill_salecash` + `bill_salecash_detail`
  - Cash sale header + line items
- `bill_salecredit` + `bill_salecredit_detail`
  - Credit sale header + line items
- `quatation` + `quatation_detail`
  - Quotation header + line items
- `clientbuy`, `clientrecive`
  - Customer buy/receive flow

## Purchase

- `bill_buy` + `bill_buy_detail`
  - Purchase header + line items
- `bill_buy_credit` + `bill_buy_credit_detail`
  - Credit purchase
- `bill_buy_order` + `bill_buy_order_detail`
  - Purchase order
- `buy_return` + `buy_return_detail`
  - Return to supplier

## Inventory and Product

- `product`, `product_unit_price`, `product_lot`
  - Product master + price + lot
- `stockcard`
  - Stock movement ledger
- `buy_latest_price`, `sale_latest_price_cash`, `sale_latest_price_credit`
  - Last price snapshots

## AR/AP

- `deptor_*`
  - Accounts receivable billing/payment/refund
- `creditor_*`
  - Accounts payable billing/payment/refund

## System / Security

- `unpw`, `user_access`, `act_log`
  - Users, access rights, action logs

## Performance tuning applied

- Added missing non-unique indexes for common lookup columns:
  - `*_id`
  - date fields (`*date*`)
  - operational keys (`username`, `bill_id`, `prd_id`, etc.)
- Implemented server-side pagination/filter/order via DataTables API
- Added schema metadata cache to reduce repeated information_schema queries

## Legacy menu and permission mapping

- Source mapping: `O:\Inventory2026\Main\Inventory\MDIParent1.cs` and `MDIParent1.Designer.cs`
- Implemented config: `config/legacy_modules.php`
- Permission check: `user_access.form_id` + `permision=true`
- Placeholder modules are intentionally kept where legacy source had menu item without handler.
# SynergyERP RAG AI Blueprint

## Goal

Build a paid AI assistant that answers ERP operation questions from manuals,
SOPs, reports, documents, and permission-aware read-only database views.

The assistant should help users find information and draft summaries. It should
not bypass approvals, accounting review, HR policy, or management decisions.

## Knowledge Sources

- user manuals in `docs/`
- department SOPs
- sales and purchase document templates
- inventory and warehouse reports
- accounting and tax reports
- HR policy and payroll reports
- manufacturing BOM, routing, lot, QC, and maintenance reports
- read-only database views created for AI retrieval

## Suggested Read-only Views

- `ai_sales_summary`
- `ai_purchase_summary`
- `ai_inventory_balance`
- `ai_stock_movement`
- `ai_debtor_creditor_summary`
- `ai_gl_trial_balance`
- `ai_hr_attendance_summary`
- `ai_mfg_lot_traceability`
- `ai_mfg_qc_summary`

These views should expose only fields needed for answers and should be filtered
by user role, department, and permission.

## Example Questions

- What products are low in stock this week?
- Which customers still owe payment?
- Summarize sales and purchase this month.
- Which production lots used this raw material?
- What does the manual say about receiving stock?
- Which GL accounts changed the most this period?
- What HR attendance issues should management review?

## Guardrails

- Do not post transactions automatically.
- Do not approve payroll, accounting, purchase, or production actions.
- Always cite manuals, reports, or database snapshots.
- Use role and department filters before retrieval.
- Redact sensitive employee, customer, and supplier fields unless the user has
  explicit permission.
- Log AI questions and referenced sources.
- Keep model/API keys outside Git.

## Delivery Phases

### Phase 1: Manual RAG

Index manuals and SOPs. Answer how-to questions with citations.

### Phase 2: Report RAG

Index generated ERP reports and dashboard exports.

### Phase 3: Database RAG

Add read-only SQL views and permission-aware retrieval.

### Phase 4: Workflow AI

Add guided summaries, draft reports, anomaly explanations, and daily management
briefs.

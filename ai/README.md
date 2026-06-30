# SynergyERP AI Add-on

This folder contains the public scaffold for the paid RAG AI add-on.

The add-on should be deployed per client and connected to that client's private
documents, generated reports, database views, and role permissions. Do not
commit production documents, API keys, vector database files, embeddings, or
client exports.

## Files

- `rag.config.example.json` - demo-safe configuration shape

## Runtime Responsibilities

- load provider keys from environment variables
- index only approved documents and reports
- enforce role and department filters
- cite manuals, reports, or database snapshots
- log AI usage for audit
- keep generated answers separate from official approvals or accounting entries

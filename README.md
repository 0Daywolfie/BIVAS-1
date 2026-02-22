
---

## 3) `README.md` (nice and professional)

```md
# BIVAS-1

BIVAS-1 is a database-first MVP blueprint for a visitor and access control system designed for gated estates and controlled facilities.

## What’s Included
- `schema.sql` — MySQL database schema for the MVP
- `er-diagram.md` — Entity-Relationship diagram (Mermaid) for quick visual review

## Core Features (MVP Scope)
- Estate, Unit, Resident management
- Visitor registry
- Resident-created visit requests (pre-approvals)
- OTP/QR-style access code support (token field included)
- Gate entry logging by security staff (check-in/out, decision, method)
- Optional: vehicle tracking
- Optional: incident reporting

## Notes
- For production, store `access_code` as a hashed token, not plaintext.
- `visit_requests.visitor_id` is nullable to support inviting unknown visitors (store name/phone instead).
- `gate_entry_logs.visit_id` can be NULL to support walk-in visitors.

## Quick Start
1. Create a MySQL database
2. Run the schema:

```sql
SOURCE schema.sql;
# BIVAS-1 ER Diagram (Mermaid)

This ER diagram models an estate visitor + access control MVP:
- Estates contain Units
- Units house Residents
- Residents create Visit Requests for Visitors
- Security staff verify entries and create Gate Entry Logs
- Optional: Vehicles + Incident reporting

## Diagram

```mermaid
erDiagram
  ESTATES ||--o{ UNITS : has
  UNITS ||--o{ RESIDENTS : houses
  ESTATES ||--o{ SECURITY_STAFF : employs

  VISITORS ||--o{ VISIT_REQUESTS : invited_for
  RESIDENTS ||--o{ VISIT_REQUESTS : creates
  UNITS ||--o{ VISIT_REQUESTS : destination
  ESTATES ||--o{ VISIT_REQUESTS : within

  VISIT_REQUESTS ||--o{ GATE_ENTRY_LOGS : results_in
  VISITORS ||--o{ GATE_ENTRY_LOGS : logs
  SECURITY_STAFF ||--o{ GATE_ENTRY_LOGS : records
  ESTATES ||--o{ GATE_ENTRY_LOGS : within

  RESIDENTS ||--o{ VEHICLES : owns
  VISITORS ||--o{ VEHICLES : uses

  SECURITY_STAFF ||--o{ INCIDENT_REPORTS : files
  GATE_ENTRY_LOGS ||--o{ INCIDENT_REPORTS : related_to
  ESTATES ||--o{ INCIDENT_REPORTS : within
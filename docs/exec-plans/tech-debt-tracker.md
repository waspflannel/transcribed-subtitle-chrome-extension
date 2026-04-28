# Technical Debt Tracker

Track cleanup continuously. Prefer small, targeted follow-up plans over large periodic rewrites.

| ID | Area | Issue | Impact | Proposed Fix | Status |
| --- | --- | --- | --- | --- | --- |
| TD-001 | Harness | Stack checks were missing before Phase 01. | Agents could not validate implementation work beyond docs. | Added contract, Laravel, and WXT checks to `scripts/agent/check.ps1`. | closed |
| TD-002 | Extension dependencies | WXT scaffold dependency tree reports four moderate npm audit advisories. | Build tooling may need package updates after WXT or transitive dependencies publish fixes. | Re-run `npm audit` during Phase 02 and update dependencies only when the fix is non-breaking or required. | open |

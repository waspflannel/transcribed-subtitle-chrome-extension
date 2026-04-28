# Golden Principles

Promote repeated human feedback into enforceable rules.

## Starting Rules

- Prefer shared utilities over repeated local helpers.
- Validate data at boundaries or rely on typed SDKs.
- Keep dependency directions explicit.
- Keep logs structured.
- Keep files small enough to review and reason about.
- Keep plans and docs current when behavior changes.

## Promotion Path

When a rule matters repeatedly:

1. Document the rule.
2. Add a check or lint.
3. Add tests where behavior is involved.
4. Add remediation text that tells agents how to fix violations.

# Review

## Review Policy

Review should focus on correctness, maintainability, boundaries, validation, security, reliability, and user-facing behavior.

## PR Evidence

Every meaningful PR should include:

- What changed.
- Why it changed.
- Validation commands and results.
- Screenshots, videos, logs, or traces when relevant.
- Known limitations and follow-up debt.

## Agent Review Loop

For substantial changes:

1. Run local validation.
2. Self-review the diff.
3. Request targeted review where useful: architecture, tests, security, UI, reliability, docs.
4. Address feedback.
5. Re-run validation.

## Promote Feedback

If the same issue appears twice, add or update one of:

- A doc rule.
- A script.
- A lint.
- A test.
- A template.
- A quality score entry.

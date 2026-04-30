# Review

## Review Policy

Review should focus on correctness, maintainability, boundaries, validation, security, reliability, and user-facing behavior.

Review should also challenge avoidable complexity. Prefer code that is direct, self-documenting, and proportionate to the phase being delivered.

Use `docs/quality/golden-principles.md` as the durable rule set for repeated readability and simplicity feedback.

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
3. Remove scaffold code, repeated helpers, defensive fallback paths, and premature abstractions that are not justified by current phase evidence.
4. Request targeted review where useful: architecture, tests, security, UI, reliability, docs.
5. Address feedback.
6. Re-run validation.

## Simplification Checklist

Before asking for review, check whether the change:

- Uses framework or platform built-ins before custom helpers.
- Keeps validation at boundaries instead of revalidating already typed internal messages.
- Avoids fallback systems for failures that have not happened yet.
- Keeps tests outside business code and focused on product behavior.
- Splits large entrypoint files into named functions or sections when that improves scanning.
- Promotes repeated helpers into shared utilities.

## Promote Feedback

If the same issue appears twice, add or update one of:

- A doc rule.
- A script.
- A lint.
- A test.
- A template.
- A quality score entry.

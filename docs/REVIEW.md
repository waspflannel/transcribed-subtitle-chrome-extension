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

## Architecture Review Reports

- Whole-codebase architecture review, 2026-05-20: `docs/architecture-review-report-2026-05-20.md`
- Whole-codebase architecture review, 2026-06-09: `docs/architecture-review-report-2026-06-09.md` (all 12 findings closed by `docs/exec-plans/completed/2026-06-09-architecture-review-cleanup-2026-06-09.md`)

## Agent Review Loop

For substantial changes:

1. Run local validation.
2. Self-review the diff.
3. Remove scaffold code, repeated helpers, defensive fallback paths, and premature abstractions that are not justified by current phase evidence.
4. Remove stale states, fields, routes, schemas, tests, and docs from any previous workflow that the product no longer exposes.
5. Request targeted review where useful: architecture, tests, security, UI, reliability, docs.
6. Address feedback.
7. Re-run validation.

## Simplification Checklist

Before asking for review, check whether the change:

- Uses framework or platform built-ins before custom helpers.
- Keeps validation at boundaries instead of revalidating already typed internal messages.
- Avoids fallback systems for failures that have not happened yet.
- Inlines one-use helpers when the helper name adds less clarity than the code itself.
- Avoids generic request helpers, status machines, enums, adapters, or services for a single current product path.
- Persists only fields that are read by current product behavior, diagnostics, retention, or validation.
- Keeps API/resources shaped directly by the contract instead of by internal models or older workflows.
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

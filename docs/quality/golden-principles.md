# Golden Principles

Promote repeated human feedback into enforceable rules.

## Starting Rules

- Prefer shared utilities over repeated local helpers.
- Validate data at boundaries or rely on typed SDKs.
- Keep dependency directions explicit.
- Keep logs structured.
- Keep files small enough to review and reason about.
- Keep plans and docs current when behavior changes.

## Simplicity And Readability Rules

- Prefer the simplest direct implementation that proves the current phase.
- Do not add fallback paths, polling loops, scoring systems, caches, or defensive wrappers unless the phase has evidence that the simpler path fails.
- Trust internal typed boundaries after validation has already happened. Validate untrusted input at the edge, then pass typed values through the app.
- Use built-in platform APIs and framework utilities before writing custom helpers.
- Promote repeated helpers into a shared utility instead of redefining them in entrypoints or feature files.
- Inline one-use, one-line helpers when the call site stays readable. Keep a helper when it names real domain behavior, hides real complexity, or has multiple call sites.
- Do not create a class, service, adapter, enum, status machine, or generic request helper for a single current path unless it protects a real boundary.
- Delete obsolete routes, fields, states, tests, and docs when a workflow is simplified. Do not keep compatibility code for paths the product no longer exposes.
- Keep normalization code readable: create the output object directly, apply obvious field checks inline, and return the object.
- Construct API/resource response objects directly from the contract. Avoid generic mappers or optional fields that only exist because an older workflow needed them.
- Persist only data the current workflow needs for product behavior, diagnostics, or retention. Avoid progress/status columns until they are user-visible or operationally required.
- Keep UI and runtime files organized into named sections or small functions when a file starts coordinating multiple concerns.
- Keep tests separate from business code, and remove scaffold tests that do not prove product behavior.
- Prefer one clear product path before generalizing for hypothetical platforms, providers, states, or future review systems.

## Promotion Path

When a rule matters repeatedly:

1. Document the rule.
2. Add a check or lint.
3. Add tests where behavior is involved.
4. Add remediation text that tells agents how to fix violations.

# Brain / Worker

An opt-in engineering work mode for implementing a plan through bounded worker assignments and user-accepted chunks.

## Select This Mode

Say **"Use brain / worker for this plan."** The lead agent reads this runbook, records `Work mode: Brain / Worker` in the owning execution plan, and follows it across sessions until the user changes the mode. This selects the workflow; it does not authorize every chunk in the backlog or any chunk merge.

Follow root and nested AGENTS.md, relevant project docs, and the selected plan. This mode narrows the general autonomy guidance: agents perform non-UI checks; the user owns UI acceptance. Explicit user instructions take precedence. Read-only investigation and planning may proceed while acceptance or merge approval is pending.

## Roles

| Role | Authority and responsibility |
| --- | --- |
| Brain / Lead | Own requirements interpretation, architecture, packet scoping, delegation, integration, review flow, and merge-readiness evidence. Own Git operations and durable chunk notes. Delegate implementation and repair code to workers. |
| Builder / Worker | Run as **`gpt-5.6-luna` with `xhigh` reasoning**. Implement one bounded packet, run relevant non-UI checks, self-review, and return results or blockers. |
| Independent reviewer | Review consequential changes. Must be a different agent/person from the implementing worker. Return actionable findings with evidence; do not expand or implement the packet. |
| User | Own browser/desktop UI testing and final phase acceptance. Explicit chunk acceptance and approval are required before chunk merge. |

Engineering roles do not acquire product-domain authority. Where doctor/AI product roles exist, they remain separate: the doctor remains clinical authority and clinical acceptance is user-owned and explicit. This subtitle project currently has no clinical acceptance gate; record it as not applicable unless the selected scope introduces one.

## Chunk Backlog And Continuity

- Break the selected plan into ordered **chunks** with concrete outcomes, dependencies, and acceptance criteria. A chunk is a reviewable unit of delivery; a packet is one worker assignment within it.
- **Only one top-level chunk is active at a time**, including implementation, repairs, review, and acceptance. Multiple workers may handle independent packets inside that chunk when file ownership and dependencies permit.
- Do not start the next chunk automatically. Finish and obtain acceptance for the current chunk, then wait for the user to select or authorize the next chunk.
- Keep the backlog and chunk notes in the owning execution plan or linked files under `docs/exec-plans/active/`. Record decisions and evidence there, not only in chat.
- On resumption, read those notes and inspect current changes before dispatching. Preserve in-progress work and existing ownership; never reset, overwrite, or silently absorb unrelated changes.

Use a compact backlog table:

| Chunk | Outcome and dependencies | State | Branch / base commit | Acceptance / merge evidence |
| --- | --- | --- | --- | --- |
| 01 | Selected bounded outcome | selected | Brain records verified base | Pending |

Use states such as queued, selected, implementing, blocked, reviewing, awaiting user acceptance, accepted, and merged. Review or test success alone does not mean accepted. Keep the current chunk active while required acceptance remains open.

## Brain Workflow

1. **Establish the base.** Verify the accepted default branch and commit from repository state and user context. Create a `codex/` chunk branch from that accepted base. Preserve unrelated working changes; use an isolated checkout when needed. Do not guess a base or include unaccepted earlier work.
2. **Read and resolve scope.** Read governing docs, the active chunk, dependencies, relevant callers, and contracts. Resolve architecture and acceptance questions before dispatch. Ask the user only for decisions that cannot be resolved within existing authority.
3. **Prepare packets.** Write a focused packet using the template below. Do not dispatch an unresolved chunk or whole phase file as the assignment. Referenced docs provide context; the packet defines the worker's scope.
4. **Dispatch.** Explicitly set `model: gpt-5.6-luna` and `reasoning effort: xhigh`. Pass the packet and required context. If these settings or delegation are unavailable, record the blocker and ask for an alternative; do not silently change models or take over implementation.
5. **Coordinate.** Await completion reports or use a bounded completion wait. Avoid repeated status polling, Git inspections of worker progress, or check-in messages. Work on independent review preparation and docs while workers run. Workers sharing a checkout must have non-overlapping write ownership; sequence shared-file changes.
6. **Integrate and review.** Inspect returned changes, integrate them on the chunk branch, and review correctness, boundaries, and simplicity. Obtain independent review for consequential changes. Consolidate worker blockers and review findings into one coherent repair packet per repair round; return it to a worker and repeat review as needed.
7. **Verify delivery.** Run relevant non-UI checks on the integrated result, including the root harness where applicable. Record exact commands, results, scope, and remaining gaps. Re-run affected checks after repairs; avoid redundant checks without a new reason.
8. **Request acceptance.** Provide a concrete reviewable diff, outcome summary, manual UI steps, and evidence. The user performs browser/desktop testing and explicitly accepts the chunk. If there is no UI impact, record UI checks as not applicable; user chunk acceptance is still required.
9. **Merge and stop.** Merge into the accepted default only after user acceptance and explicit approval to merge that chunk. One user message may provide both. Local integration on the chunk branch is not this merge gate. If acceptance reveals defects, repair and verify before requesting acceptance again. Record the accepted revision, approval, and merge result. Stop at the chunk boundary.

## Worker Packet Template

Save each packet in the owning chunk notes before dispatch. Include enough concrete context for the worker to act without interpreting the entire backlog.

```text
Packet ID / owning chunk:
Role and authority: Builder / Worker; implement only this packet.
Settings: model gpt-5.6-luna; reasoning effort xhigh.
Objective: One observable outcome.
In scope:
Out of scope:
Context: Governing docs, relevant files/callers, current behavior and reproduction.
Contracts and invariants: Inputs/outputs, identities, error behavior, boundaries to preserve.
Ownership: Checkout path, allowed files, shared-file restrictions, other active owners.
Checks: Exact relevant non-UI commands and expected outcomes.
User-owned acceptance: Manual browser/desktop steps; clinical items if applicable.
Restrictions: No extra scope, Git history operations, further delegation, or UI testing.
Return: Changed files and behavior; checks/results; self-review; blockers; remaining acceptance.
```

Workers may inspect Git status/diffs but must not stage, commit, branch, merge, rebase, cherry-pick, reset, push, or switch checkouts. The Brain owns those operations. Workers must not overwrite another owner's work, add unassigned dependencies, or change contracts outside the packet. If the objective requires that work, return a blocker with evidence and the needed decision. Do not make speculative adjacent fixes.

Each completion report must identify the packet, changed files, behavior, exact non-UI checks and results, self-review findings, unresolved blockers, and user acceptance items. Distinguish completed work from proposed work and unrun checks.

## Review And Evidence

Independent review is required only for consequential changes: for example billing, authentication/authorization, data loss or migrations, concurrency/ownership, shared contracts, provider failure handling, or substantial architecture changes. The Brain records whether the chunk is consequential and why. Routine low-impact changes use worker self-review and Brain review. If independent review is required but unavailable, keep that gate open.

Agents run builds, lint, typechecks, tests, and other relevant **non-UI** checks. Do not run browser/desktop automation or claim visual acceptance from automated tests. Use `scripts/agent/check.ps1` for implementation; documentation-only changes use `scripts/agent/check.ps1 -SkipAppChecks` and relevant documentation checks. Live external actions still require applicable authorization.

Keep evidence distinct:

- Synthetic evidence: mocks, fixtures, fakes, in-memory databases, and simulated provider responses.
- Real runtime evidence: actual services, databases, provider responses, and logs, with environment and scope recorded.
- User acceptance: explicit UI/clinical results where applicable, chunk acceptance, and merge approval tied to the reviewed revision.

Synthetic passes do not establish real runtime or UI success. Never mark a missing check as passed. Record failed/unrun checks and residual risks in the chunk notes. Material changes after acceptance require renewed acceptance before merge.

For continuity, retain the packet, worker identity/settings and result, ownership, blockers and repair packets, review findings/disposition, integrated check evidence, manual acceptance steps/results, and approval/merge state. Update affected docs and debt. Archive the plan only when its required chunks and final phase acceptance are complete.

# Delivery and testing record

Branch context: this is a preserved record on a documentation-only branch; [the index explains which application revisions the evidence describes](00-index.md#cleanup-branch-preservation).

Created: 2026-09-09
Last updated: 2026-09-09

Use the [index](00-index.md) for shared execution rules, ownership and the old-number map. Historical package numbers below identify audit evidence; they are sections of these consolidated documents, not separate work plans. This consolidation does not authorize new implementation or experiments.

Status: prior code delivered and reviewed; user acceptance remains open, including the reported slowdown. No merge or deployment is recorded.

This is the single scope, review and testing record for the already selected former 05/06/08 delivery. The prior reviewed revision is `c180b2b9ccd1337671ad2933f79ea46a43b4263f`; subsequent request diagnostics are tracked in the [pipeline investigation](pipeline-speed-quality-and-reliability.md#current-slowdown-investigation). Inspect the current diff before treating earlier validation as coverage of later repairs.

The sections below preserve dated delivery evidence and the full manual handoff. Past claims that application code matched the reviewed commit apply to their recorded snapshot, not automatically to the current checkout. Consolidation does not accept the code or start deferred experiments.

- [Delivery scope and decisions](#delivery-scope-and-decisions)
- [Independent review](#independent-review)
- [Testing handoff and deferred experiments](#testing-handoff)
- [Manual smoke tests and result table](#manual-smoke-tests)

## Delivery scope and decisions

Status: awaiting user acceptance
Owner: Brain / Lead
Work mode: Brain / Worker
Created: 2026-09-09
Last updated: 2026-09-09

### Goal

Deliver demonstrated code defects and specified behavior in packages 05, 06 and 08 as one top-level chunk, using bounded Luna/xhigh builders. Deliver reviewed grouped commits and a testing handoff. User owns UI tests, acceptance and merge approval.

### Scope

In scope: honest degraded learning output, useful cards, case/spelling edits and safe ambiguous quick-fix recovery; actual AI request limits and unit budgets preserving existing account leases; narrowly justified adjacent boundary reconciliation. Necessary regressions use offline fakes.
Out of scope: the [ai latency and linguistic quality](pipeline-speed-quality-and-reliability.md#ai-latency-and-linguistic-quality) section; all model/effort, scheduling, audio preprocessing, upload, URL-ingestion and chunk-size comparisons; paid generation; external runtime mutations; deploy/merge. The later user-authorized push is recorded below. No new top-level chunk without authorization.

### Chunk backlog

| Chunk | Outcome and dependencies | State | Branch / base commit | Acceptance / merge evidence |
| --- | --- | --- | --- | --- |
| 05-06-08 code | Delivered code plus non-UI evidence and handoff. Narrow prerequisite gaps recorded below. | awaiting user acceptance | codex/learning-queue-audio-05-06-08 / 75dbd046f8ef6b71226db85ee271919421dae60b | Pending; no merge authorized |
| Deferred experiments | Compare alternatives after trusted baselines, resources and authorization. | queued, unauthorized | none | none |

### Acceptance Criteria

- [x] Degraded output preserves readable source and explicitly identifies missing derived features.
- [x] Useful minimum cards and case-only edits are tested; late result recovery preserves identity.
- [x] Actual requests and inline work are bounded; existing fairness/cleanup boundaries survive.
- [x] Both boundary counterexamples are corrected with repeated-word safeguards.
- [x] Independent consequential review, repairs and integrated harness complete.
- [x] Exact reviewed commit and manual/deferred-test handoff delivered.
- [ ] Explicit user acceptance and merge approval (required later; not inferred from tests).

### Relevant Context

Root/nested instructions; Brain/Worker runbook; PLANS, AUTONOMY, USING_AGENT_HARNESS; source review and selected 00/05/06/08 documents. The [source text and token fidelity](pipeline-speed-quality-and-reliability.md#source-text-and-token-fidelity) section/03/04 documents were inspected. Main, origin/main and origin/HEAD agree at the dated review base; no earlier unaccepted feature branch is included. At delivery start, unrelated review/runbook documents were dirty and were preserved. They were subsequently committed with explicit user authorization.

### Implementation Steps / packet order

1. 08-A: isolated merger correction and regressions (independent of 05).
2. 05-A: degraded feature contract through provider, assembly and display.
3. 05-B: minimum cards and safe case/spelling/late quick-fix recovery; sequence shared provider/display files after 05-A.
4. 06-A: shared actual-request guard and per-unit budgets; sequence provider and correction files after 05.
5. Integration/version/document packet as needed; independent review and one consolidated repair packet per round.
6. Group commits, run integrated checks, create delivery-and-testing.md#testing-handoff and stop awaiting acceptance.

### Dependencies and decisions

- 02 is unimplemented: validator accepts lexical omissions/case changes and Korean cleanup remains broad. Do not silently implement all of 02. Include only the source validation floor needed for honest 05 output if its packet cannot safely degrade invalid tokens; broader Korean normalization remains separate.
- 03 is unimplemented: actual SDK overload mapping and swallowed transient reprompts need narrow inclusion for 05/06 failure semantics. Nullable Scribe timing and full Scribe retry work remain the [provider compatibility and retries](pipeline-speed-quality-and-reliability.md#provider-compatibility-and-retries) section unless a precise dependency arises.
- 04 is unimplemented: run-mixed metrics/counts prevent trustworthy comparisons, not offline limiter or merger fixes. No scheduling/adoption claim or diagnostic rewrite in this chunk.
- Consequential: yes, because shared cue contracts, provider failure handling, request concurrency and correction publication cross ownership boundaries. Independent review is required.
- Skills: Ponytail, Git Group Commits, local Laravel best practices, subtitle-pipeline and AI SDK. Existing framework/cache/queue primitives preferred; no dependency or model change. Independent review-prep agent reads required skill rules; workers may not delegate despite generic skill advice.
- Official docs: Context7 resolved laravel/docs, queried 13.x locks/timeouts/retryUntil. Review-prep independently fetched Boost search-docs via artisan MCP. Exact installed code remains the syntax reference.
- Clinical acceptance: not applicable. Automated DOM/unit tests are synthetic non-UI checks, not user acceptance.

### Validation Plan

Focused PHPUnit/Vitest/contracts per packet; php vendor/bin/pint on owned paths; integrated scripts/agent/check.ps1. Preserve synthetic, actual runtime and user evidence separately. No browser/desktop automation. No paid requests. Real audio, Linux worker termination, Postgres concurrency and browser lifecycle remain explicit unverified gates when not exercised.

### Delivery record

The selected 05/06/08 code was implemented, independently reviewed and repaired on the delivery branch. The [consolidated review](delivery-and-testing.md#independent-review) retains final decisions, reviewer ownership, focused evidence, resolved findings and remaining limits. The [testing handoff](delivery-and-testing.md#testing-handoff) lists all eight implementation commits and deferred experiments.

The final integrated harness on `c180b2b` passed 516 backend tests / 3,336 assertions, 249 extension tests, contracts, TypeScript and build. Earlier per-worker checks and repair rounds are historical; their records remain in Git at `753d0a9`.

The user authorized committing all dirty documentation and pushing the branch, completed through `753d0a9`. Subsequent documentation consolidation removed redundant worker records without changing application code or closing user acceptance gates.

### Git status and delivery

The implementation commits and documentation through `753d0a9` are pushed to `origin/codex/learning-queue-audio-05-06-08`. The user has not accepted or authorized merging the delivery. Future Git operations follow explicit user instructions.

### Completion Notes

Code delivery is complete and reviewable at c180b2b9ccd1337671ad2933f79ea46a43b4263f on codex/learning-queue-audio-05-06-08. Brain committed all implementation in eight concern-based local commits after Luna/xhigh builders and independent review. Final root harness passed: 516 backend tests / 3,336 assertions, 249 extension tests / 31 files, contracts, TypeScript and production build. See [testing handoff](delivery-and-testing.md#testing-handoff) for the commit list, manual checklist, observations and deferred experiment procedures. Original main remains at the verified base. Review/runbook changes were subsequently committed and the branch pushed with user authorization through `753d0a9`. No deployment, paid generation, UI automation or deferred experiment was performed.

User acceptance: pending. Accepted revision: none. Merge approval: none. Merge result: not performed. Keep this plan active; stop at this boundary and await the user. Material repairs require renewed review and acceptance. Clinical acceptance: not applicable.

## Independent review

Review status: delivered code cleared after independent review and repairs.
Reviewed code: `c180b2b9ccd1337671ad2933f79ea46a43b4263f`.
User testing, chunk acceptance and merge approval remain pending.

### Final decisions and evidence

| Area | Final result | Evidence / commit |
| --- | --- | --- |
| Cards and case-only edits | A useful card requires a nonblank translation or gloss; repeated occurrences keep their own identity. Case-only edits are allowed. | `088d24a`; 22 focused backend tests / 125 assertions. |
| Degraded learning and source fidelity | Invalid tokenization retains deterministic readable source. Missing requested translation/pronunciation is explicit. Source matching preserves case, order and lexical coverage without imposing a second segmentation policy. | `6e67508`, `ac6c310`; warning/correction checks: 142 tests / 527 assertions; final source checks: 20 tests / 37 assertions and direct probes. |
| Quick Fix recovery | Exact-job GET reconciliation never replays an ambiguous PATCH. Publication, persistence and expiry cleanup retain account/session/tab/video/track/operation ownership through the final asynchronous boundary. | `2ca310f`; cleared after five repairs; 49 extension tests, TypeScript compile and direct ownership/recovery probes. |
| Request-runner foundation | Nested scopes share consumed requests and the outer cancellation callback; nested failures cannot restore an obsolete counter. Fresh outer scopes reset correctly. | `bfb0c7a`; 18 tests / 69 assertions and nested/failure/callback probes. |
| Request and queue integration | Alignment and all four derived correction stages inherit enclosing deadlines. Shared admission crosses service boundaries. Cancelled batches are skipped before account lease acquisition. | `c180b2b`; R06-I1/I2/Q1 cleared; 38 tests / 172 assertions plus ten real-wrapper scope probes. |
| Audio boundaries | Match only mutually unique, same-text, overlapping occurrences near adjacent boundaries. Preserve unmatched gaps, source order, timestamps and attachments without global sorting. Distinct rapid repeats survive. | `07682be`; cleared after three repairs; 40 tests / 100 assertions plus ordering probes. |
| Processing versions | JOB v10, transcript cache v3 and learning-token cache v9 distinguish changed generated output. Existing identity checks remain. | `51e75fe`; consumer inspection and focused cache/reuse tests. |

### Details retained from the repair reviews

- Warning accumulation deduplicates valid stage warnings; malformed individual artifacts still fail validation. Lyrics correction carries warning state through atomic publication. Later authoritative enrichment can clear repaired romanization warnings without clearing unrelated translation/tokenization warnings. The empty-translation contract rule belongs to the partial cue schema.
- Source probes rejected omitted words, changed casing, `cat` becoming `at`, and word redivision such as `the rapist` becoming `therapist`. Mixed Japanese/Chinese and Latin segmentation, repeated words, punctuation, collapsed whitespace and combining marks remain supported. Broader Korean-space normalization and linguistic usefulness remain separate work.
- Recovery markers contain no replacement text. A ten-minute age threshold allows retirement only after authoritative unchanged/missing-job evidence or explicit supersession; transport/5xx/malformed responses can retain the marker longer. Serialized compare-and-set writes protect remembered tracks. Final publication reads the native tab before the last ownership/state check, with no later asynchronous gap before emission.
- Request budgets cap an operation at eight requests / 240 seconds and reserve five seconds below a smaller enclosing timeout. Existing narrower agent timeouts remain. A 120-second correction job gets at most 115 seconds; a 300-second job still gets at most 240. Nested scopes cannot bypass an outer cancellation callback. Disabling the configured global rate gate does not disable operation budgets.
- Integration regressions exercised an alignment request exhausting admission before a separate card call, persisted run reset stopping recursion without stale artifacts/events, and cancellation of a queued batch while the account's sole lease was already occupied. These use framework/DB state and SDK fakes, not production services.
- Audio regressions covered distinct adjacent `yeah, yeah` occurrences, inverse timestamp ordering, left/right unmatched predecessors and interleaving, opposite-chunk matched winners and multiple boundaries. Equal text/timing alone cannot prove acoustic identity. Unequal text, ambiguous repeats, nonoverlapping predictions and non-adjacent duplicates can remain imperfect under midpoint ownership. The downstream normalizer's existing ordering was not changed or certified for arbitrary timing errors.

### Final integrated validation

The recorded final harness on the reviewed code passed **516 backend tests / 3,336 assertions**, **249 extension tests / 31 files**, contracts, TypeScript compile and production build. Earlier worker counts and intermediate failing reviews are historical, not the final disposition.

Independent reviewers differed from implementing builders. `review_prep` reviewed cards, degraded/source behavior, request foundation/integration and audio; `builder_audio` independently reviewed Quick Fix recovery and queues. The lead reviewed integration and processing-version consumers. No required code-review finding remained at the final reviewed revision.

### Remaining limits and follow-up

- Real browser reconnect/navigation/two-tab behavior, bilingual quality and real-audio recognition remain unverified. User acceptance is separate from synthetic/build checks.
- Linux Postgres/Redis contention, worker termination, sustained fairness, provider capacity and stalled-work recovery need the deferred runtime experiments. Fixed-minute admission is not a rolling-minute or token-per-minute quota. Future SDK retries, tools or failover require rechecking prompt-to-request accounting.
- Ordinary Generate can reuse a degraded ready track. Quick Fix clears only successfully repaired derived warnings; rebuilding tokenization requires an action that actually rebuilds it. Pasted lyrics reuse existing cue slots rather than acoustic alignment.
- Optional test-quality follow-up: an earlier authoritative-enrichment partial fixture supplied empty translation without `translation_unavailable`, so it did not prove that warning survives romanization repair. The reviewer confirmed correct behavior with valid data, but the final source-only review did not verify fixture refinement. Recheck that fixture before claiming this precise regression coverage; this was not a remaining implementation blocker.
- The [testing handoff](delivery-and-testing.md#testing-handoff) owns manual acceptance, deferred experiment procedures and remaining package dependencies. The [smoke checklist](delivery-and-testing.md#manual-smoke-tests) is the first manual test pass.

### Historical records

On 2026-09-09, the user requested consolidation of the 37 worker assignment/result files and eight intermediate review files. Their final decisions, validation and limits are summarized above. Detailed reproductions and per-round records remain in [Git history before consolidation](https://github.com/waspflannel/transcribed-subtitle-chrome-extension/tree/753d0a9e4a43811c84a20606ed234d94c68f6fe1/docs/exec-plans/active/2026-09-09-whole-project-review). Locally, use `git show 753d0a9:<repository-relative-path>`.

The branch was pushed with user authorization through `753d0a9`. That push did not establish UI acceptance or merge approval.

## Testing handoff

Status: code implemented, independently reviewed and automatically checked; awaiting user testing, chunk acceptance and merge approval.
Work mode: Brain / Worker
Branch: `codex/learning-queue-audio-05-06-08`
Base: `75dbd046f8ef6b71226db85ee271919421dae60b` (`main` and local `origin/main` at start).
Exact reviewed code commit: `c180b2b9ccd1337671ad2933f79ea46a43b4263f`. Final notes are committed afterward; application/contracts code must match this revision.
Owning plan: [code delivery](delivery-and-testing.md#delivery-scope-and-decisions).

For a first manual pass with examples and expected results, use [delivery-and-testing.md#manual-smoke-tests](delivery-and-testing.md#manual-smoke-tests).

### Changes delivered

- **05:** readable deterministic source fallback with explicit tokenization/translation/romanization warnings through partial/final contracts and rendering; source case/order/coverage checks; warnings preserved through pasted-lyrics replacement and cleared only by successful derived repair; useful on-click cards require a meaning and distinguish repeated token occurrences; case-only Quick Fix is permitted. Ambiguous Quick Fix outcomes reconcile the exact job on explicit refresh; ownership guards and serialized expected-track writes protect newer session/tab/track state, without PATCH replay.
- **06:** every current OpenAI prompt uses shared atomic fixed-minute admission, including interactive cards/edits and alignment retries. Each operation has a shared maximum of eight requests / 240 seconds; correction scopes clamp below their enclosing job timeout and preserve the narrower agent timeout. Local admission denial releases queued work separately from real provider retries. One account shares batch lease occupancy across tiers; cancelled batches are skipped before leasing. Actual processor run guards stop recursive requests after cancellation/reset. Queue topology and scheduling are unchanged.
- **08:** adjacent-boundary reconciliation for uniquely matched overlapping words, retaining native timestamps, untimed attachments and source order. Ambiguous repeated matches keep midpoint behavior. Audio preparation, acquisition and chunk settings are unchanged.
- **Reuse:** JOB `scribe-v2-tokenizer-v10-async-`, transcript cache `transcript-chunks-v3`, and learning-token cache `learning-token-v9`. Existing identity-based access remains; future compatible lookups distinguish the new processing output.

The [consolidated review](delivery-and-testing.md#independent-review) records final decisions, review evidence and historical records. No whole package or comparative experiment is declared complete from code alone.

### Delivered commit groups

| Commit | Concern |
| --- | --- |
| `088d24a` | Useful word cards and case-only edits. |
| `07682be` | Narrow adjacent-boundary word reconciliation. |
| `bfb0c7a` | Shared request runner and bounded admission foundation. |
| `51e75fe` | Processing and learning-cache version invalidation. |
| `6e67508` | Degraded cue warnings through backend, contracts and display. |
| `ac6c310` | Mixed-script source spacing and case/coverage validation. |
| `2ca310f` | Late Quick Fix recovery and asynchronous ownership guards. |
| `c180b2b` | Actual request-budget integration, correction deadlines, account leases and cancellation. |

The delivery, audit, runbook and first documentation cleanup were committed and pushed with user authorization through `753d0a9`. Compare the delivery branch to the base above. This consolidation changes documentation only; the reviewed application code remains at the recorded revision.

### Automated checks

Final integrated command: `scripts/agent/check.ps1` passed on the reviewed code with **516 backend tests / 3,336 assertions**, **249 extension tests / 31 files**, contracts validation/generated types, TypeScript compile and the production extension build (`app/extension/.output/chrome-mv3`). Worker-owned PHP Pint/lint passed. Final independent 06 review passed 38 focused tests / 172 assertions and ten deadline/callback probes; all findings from both 06 reviews are resolved. Final focused evidence and the historical record are linked from the consolidated review. Documentation-only validation after the final notes passed: `scripts/agent/check.ps1 -SkipAppChecks` and `git diff --check`. PHPUnit SQLite/array-cache/HTTP fakes and Vitest simulated extension APIs are synthetic evidence. Builds and typechecks establish compilation, not UI acceptance or production concurrency. No agent browser/desktop automation is permitted in this work mode.

### Limitations and unverified assumptions

- Real audio has not established recognition quality or the real incidence of the synthetic midpoint defects. The bounded merger can only use returned text/timestamps; ambiguous alignments cannot be assumed to refer to the same spoken word.
- The [source text and token fidelity](pipeline-speed-quality-and-reliability.md#source-text-and-token-fidelity) section Korean-space normalization and broader linguistic validation remain separate. Narrow prerequisite token fidelity does not establish a bilingual quality floor.
- The [provider compatibility and retries](pipeline-speed-quality-and-reliability.md#provider-compatibility-and-retries) section Scribe nullable timing, no-speech policy and retry/Retry-After work remain separate unless explicitly recorded in delivered scope.
- The [measurement and cost](pipeline-speed-quality-and-reliability.md#measurement-and-cost) section run-mixed metrics, undercounts and full attempted-usage costs are still prerequisites for trustworthy comparisons. Zero configured estimated cost is not zero provider cost.
- Windows non-UI tests do not establish Linux worker termination, parallel Postgres publication, Redis outages or sustained Base/Plus/Pro fairness.
- The configured OpenAI request guard uses shared fixed-minute buckets. Requests may burst across a minute boundary; this is not a rolling 60-second guarantee, a measured provider quota or a token-per-minute limiter. Scribe request policy and its separate nullable-timing/retry findings remain the [provider compatibility and retries](pipeline-speed-quality-and-reliability.md#provider-compatibility-and-retries) section. Existing tier queue scheduling remains unchanged.
- Current agents select one OpenAI provider and have no tools or failover list; the installed SDK path makes one HTTP attempt per admitted prompt. Future SDK retries, tool loops or provider failover would require rechecking that accounting boundary. Requests denied locally can wait through queue releases; the scheduled stalled-work backstop must remain enabled and running.
- A browser timeout does not prove a server edit failed. Never automatically repeat an ambiguous PATCH. Reconcile the authoritative exact job and preserve account/session/video/track identity.
- Quick-fix recovery markers are evaluated on explicit synchronized refresh. The ten-minute age threshold permits cleanup after an authoritative unchanged/missing job result; it does not establish failure while offline. Unavailable reads can retain a marker longer, without periodic retry or PATCH replay.
- Existing pasted lyrics use the original cue slots; they do not perform acoustic forced alignment.
- Models, effort, Fast mode, queue scheduling, worker counts, source selection, audio preparation, upload formats and chunk sizes are unchanged by experimental adoption.
- User UI testing, explicit chunk acceptance and merge approval remain open. The branch was pushed with user authorization through `753d0a9`; deployment and merge have not been performed.

### Manual checklist (user-owned)

1. Load the built extension and current local backend. Record browser/extension/backend revision and runtime settings; use existing tracks or authorized fixtures before authorizing real generation.
2. Compare complete and degraded fixture tracks in transcript and overlay. Source remains readable; absent requested translation/pronunciation is clearly labelled; optional settings do not imply missing work that was never requested. Search/copy and native cue timing still work.
3. Open a word with only lemma/POS metadata, then one with a meaning. Verify a useful meaning appears, incomplete failures stay retryable and repeated occurrences retain the selected meaning/identity.
4. Change only casing, then spelling, then the second of two identical words. Confirm only that occurrence changes, timing is unchanged, cards/derived cue output refresh, and unrelated cue cards survive.
5. Interrupt connectivity during quick fix; reconnect/reopen the same job after the backend might have completed. Confirm the authoritative revision is recovered and the edit is not sent twice. Repeat while navigating, opening another generation and signing out/in; late work must not replace unrelated state.
6. With authorized deterministic delayed/failing backend fixtures, try generation/card/edit operations under rate denial and retry. Confirm clear failure/retry state, source availability where applicable, cancellation and later recovery. Do not infer capacity from this small manual run.
7. Listen around known chunk boundaries in already-authorized audio and compare a reference transcript: missing/duplicate/reordered words, repetitions and timestamp offsets. Record audio/model/chunk conditions. This is real-audio validation only if actual audio is examined; synthetic fixtures alone do not satisfy it.

### User observations and reproduction details

Copy this block for each observation:

- Date/time and tested commit:
- Browser/extension/backend versions and relevant settings:
- Account tier (no credentials), video/job/run/track/cue IDs:
- Fixture/audio provenance and permission; expected reference text/timing:
- Exact steps, including navigation, network interruption and elapsed time:
- Expected versus actual result:
- Reproducibility (attempts/failures), sanitized logs/screenshots supplied by user:
- Does it occur after reopening the exact job? Did the backend commit a new track ID?
- Brain/worker reproduction, repair packet and regression evidence:
- Retested revision and explicit acceptance/merge decision:

### Deferred experiments — do not start automatically

Every adoption comparison holds input, code, prompt/version, model, features, tier, topology and cache state constant except the named variable. Record all attempted requests/costs and uncertainty. No model winner or performance improvement is assumed.

| Experiment | Baseline and procedure | Required resources | Acceptance criteria | Authorization |
| --- | --- | --- | --- | --- |
| Reliable run/cost baseline (04 prerequisite) | Fix run-pure metrics/counts first; separately label ready reuse, transcript hit and miss. Start with short/medium/near-limit references and repeated matched runs. | The [measurement and cost](pipeline-speed-quality-and-reliability.md#measurement-and-cost) section work selection, sanitized immutable run records, actual provider usage/prices and quota, representative corpus. | No mixed-run timing/cost; attempted failures/retries counted or explicitly unavailable; sample counts reported, no tail/SLA claim from a tiny sample. | Separate the [measurement and cost](pipeline-speed-quality-and-reliability.md#measurement-and-cost) section implementation selection; explicit capped paid-generation approval for live samples. Historical estimates are not budgets. |
| Learning/bilingual quality (05) | Compare current reviewed fallback/card behavior with fixed reference cues under malformed/empty/adversarial fake outputs; then bilingual review of selected real outputs. | Offline HTTP/agent fixtures; bilingual readers; source and pronunciation references. | No source loss or hidden absent features; useful meanings in context; bounded repair requests; no identity/timing regression. | Offline code regressions remain in scope; any new provider generation/evaluation needs capped approval. User owns visual/linguistic acceptance. |
| Capacity/fault/fairness (06/E06) | Keep existing queue topology. In disposable Linux Postgres/Redis use delayed fake providers and multiple Base/Plus/Pro accounts; kill workers, deny Redis access, cancel/reset and sustain Pro pressure. Observe leases, request counts, queue states, settlement and cleanup. | Isolated Linux workers, scratch database/Redis and fake provider endpoint; topology and logs; no production/shared data. | Request bounds hold; no stale publication/double settlement; eventual lease/workspace cleanup and no stranded jobs; quantify Plus wait/starvation and Base reserve separately. | Explicit authorization before starting this deferred fault experiment; zero paid calls for fake phase. Real-provider phase separately needs quotas and capped paid approval. |
| Scheduling alternatives (06) | Current fixed analysis→romanization chains versus one candidate at a time (balanced batches, analysis-first or dynamic continuation), on identical workload. | Trusted the [measurement and cost](pipeline-speed-quality-and-reliability.md#measurement-and-cost) section metrics, delayed fixtures plus authorized real workload, above fairness controls. | Proposed gate: ≥15% matched p50 benefit, no >5% p95 regression, no quality/identity/fairness loss; agree sample and thresholds before adoption. Record first translated cue and completion separately. | User selects experiment and any implementation; paid runs separately capped. No automatic scheduler change. |
| Real boundary validation and chunk tuning (08/E04) | First compare reviewed narrow merger against old midpoint fixture result and authorized real returned payloads/audio. Whole-file transcription is quality control, current fixed chunking latency control. Only later vary cut/length/overlap one at a time. | Authorized retained audio/payloads and exact chunk metadata, reference transcripts and listener; paid STT only if needed. | Both synthetic examples once/in order; real repetitions retained; no real boundary WER/CER/timing regression within ±5s; report ambiguous cases, not blanket exactly-once recognition. Tuning additionally needs measured latency/throughput benefit. | Explicit permission/resources for retained real audio; new STT needs capped paid approval. Larger original 384–720 source-minute proposal is unapproved. |
| Audio/source/upload/URL experiments (08/E03) | Current M4A-first acquisition and mono 16k FLAC versus direct supported format/PCM/channel/native-rate choices separately. Test gain/denoise/isolation only on labelled noise/music/stereo cases; provider URL ingestion is a separate acquisition path. | Same authorized sources, FFmpeg/yt-dlp versions, provider capability/quotas, CPU/RSS/network measures, references including phase opposition/overlapping speech. | No lost speech, timing shift, duration/public-video bypass or quality regression. Measure CPU, bytes, upload duration and latency separately; never remove silence without original-time mapping. | Separate experiment authorization and provider retention/cost approval; no automatic adoption. Original 96-request estimate is unapproved. |
| Model/effort/Fast changes (07, outside delivery) | Current configured model/effort/options; vary one setting per stage against fixed gold cues. | Explicit the [ai latency and linguistic quality](pipeline-speed-quality-and-reliability.md#ai-latency-and-linguistic-quality) section selection, supported official provider docs, bilingual references and trusted attempted-usage accounting. | No source/identity loss or material linguistic regression; latency/cost gates agreed before run. Coding benchmarks do not establish subtitle quality. | Separate selection and capped paid evaluation approval; do not start from this handoff. |

### Next agent instructions

1. Read AGENTS and nested backend instructions, Brain/Worker runbook, owning plan, this handoff and consolidated review. Inspect `git status --short`, current branch, HEAD and diff against the recorded base/reviewed commit before dispatching. Preserve any new unrelated working-tree changes.
2. Read the user's observations. Distinguish UI acceptance, observed runtime failures and assumptions. Reproduce reported failures with the smallest offline fixture/HTTP fake or already-authorized local service; never turn a user UI report into claimed agent UI validation.
3. Keep this one chunk active through material repairs. Save a coherent bounded repair packet and delegate code/repair work to gpt-5.6-luna with xhigh. If those settings or delegation are unavailable, record the blocker and request an alternative; the Brain must not silently implement the repair or change models. Brain owns all Git operations. Obtain independent review for consequential changes; retain precise findings and dispositions.
4. Continue remaining necessary non-UI validation on the integrated revision: affected regressions, contracts/typecheck/build as relevant, then scripts/agent/check.ps1. Separate synthetic results from real runtime evidence. Request authorization only for genuinely unapproved external/paid/deferred work; do useful independent offline investigation meanwhile.
5. Treat the code commit above as the reproduction baseline; verify `git diff c180b2b9ccd1337671ad2933f79ea46a43b4263f HEAD -- app packages` is empty before attributing observations to this delivery. Update the exact reviewed revision and evidence after repairs. Material changes require renewed user acceptance. Do not auto-start deferred experiments, another chunk, push, deploy or merge. Merge only after explicit acceptance and merge approval tied to the reviewed revision; record accepted revision and merge result.

## Manual smoke tests

Use this for a first manual pass, not full acceptance of production concurrency or transcription quality. Record PASS, FAIL or NOT EXERCISED for each check.

Branch: `codex/learning-queue-audio-05-06-08`. Reviewed code: `c180b2b9ccd1337671ad2933f79ea46a43b4263f`. Later documentation commits do not change that code.

### What changed, with examples

| Package | Change | Example |
| --- | --- | --- |
| 05 | Degraded learning output is explicit and preserves source text. | If requested English translation of `Hola` is missing, it is empty with `Translation unavailable.` rather than presenting `Hola` as a successful English translation. |
| 05 | Fallback boundaries and missing requested pronunciation are labelled. | Approximate tokenization shows `Word boundaries may be approximate.`; missing requested readings show `Romanization unavailable.` |
| 05 | Word cards need a meaning; source validation preserves case and coverage. | Lemma/POS alone is insufficient. Tokenization cannot silently turn `NASA` into `nasa` or omit a source word. |
| 05 | Quick Fix permits case/spelling corrections and protects late results. | `london` → `London` is allowed. A late edit for video A cannot replace the active subtitles for video B. |
| 06 | Every current OpenAI prompt crosses shared admission and a bounded operation budget. | Recursive retries consume requests individually. With a limit of one request per fixed-minute bucket, alignment can consume it and a following card request is denied locally. |
| 06 | Correction deadlines and account occupancy are bounded consistently. | Provider timeout 30 seconds gives a 120-second correction job and a maximum 115-second request budget. One account's Base/Plus jobs share lease occupancy. |
| 08 | Adjacent chunks reconcile uniquely matching overlapping words. | Two returned copies of one boundary word can become one; two genuinely distinct `yeah, yeah` occurrences must remain two. This is a merge correction, not improved speech recognition. |

Models, effort, scheduling, audio preprocessing, upload format and chunk settings were not changed. Processing/cache versions were advanced so new compatible lookups use the changed output semantics.

### Before testing

- Run the local backend from this branch and restart any long-running workers using your normal local workflow.
- Reload the unpacked extension from `C:/transcribed-subtitle-extension/app/extension/.output/chrome-mv3`, then reload the video page.
- Use a completed track for the edit/card tests. Prefer a language pair you understand; use Japanese or another non-Latin language for pronunciation checks.
- For the [audio preparation and transcription](pipeline-speed-quality-and-reliability.md#audio-preparation-and-transcription) section, use output processed by this revision from audio long enough to be chunked. An old ready track or a short single-chunk clip does not exercise the merger change.
- Generate, uncached cards and edits can make normal provider calls. The automated commands below use test fakes and require no paid generation.

### Manual smoke checklist

#### 1. Normal learning output — 05

- [ ] Open a completed track with translation enabled. Play, seek, open the Transcript panel and copy a cue.
- [ ] Check source words, capitalization, translation and timing against what you hear.
- [ ] On a track created with romanization enabled, show the pronunciation and open a few words.

**Pass:** source remains readable, cue timing and navigation work, and complete output has no false unavailable/approximate warnings. A feature never requested must not be described as a failed feature.

#### 2. Useful word cards — 05

- [ ] Open three words, including the same word in two different contexts if available.
- [ ] Close and reopen one card.

**Pass:** a completed card has an actual translation or gloss, not only a lemma or part of speech. Reopening remains usable, and the selected occurrence gets the correct context. If an incomplete response occurs, it must not become a permanently completed empty card.

#### 3. Case, spelling and selected occurrence — 05

- [ ] In Quick fix, change only case, such as `london` → `London` (use an equivalent word in your track).
- [ ] Make a spelling correction in a different cue.
- [ ] If a cue contains a repeated word, edit only its second occurrence.

**Pass:** each edit saves; only the selected source occurrence changes; cue start/end times stay the same; translation/readings/cards refresh as requested. An unrelated cue and its card remain unchanged.

#### 4. Interrupted edit and late recovery — 05

- [ ] Submit a Quick Fix, then disconnect connectivity while it is pending. To exercise an ambiguous result, the request must have reached the backend before the response is lost.
- [ ] Restore connectivity and reopen/refresh the extension panel on that same video/job. Do not submit the edit again to recover it.
- [ ] Separately, submit an edit on video A and immediately navigate to video B. Let the original request finish, then return to A.

**Pass:** an ambiguous result may say `The edit outcome is unknown. Refresh to check whether it was applied.` Refresh reconciles the backend's actual result without resending the edit. Video B's state is never replaced by A's late result. If the backend committed the edit, it appears when A is recovered; a request that never reached the server may correctly leave the old text.

**Not exercised:** the response arrived before disconnection, or the request was prevented from reaching the server. A successful normal edit alone does not test recovery. Disconnecting the computer also interrupts a backend on that computer; distinguish that from dropping only the browser's response.

#### 5. Cancellation and subsequent work — 06, with 05 publication guards

- [ ] Start a generation or pasted-lyrics correction and cancel while it is queued/running.
- [ ] Wait for any already-started provider request to return. Refresh/reopen the job.
- [ ] Start another normal operation afterward.

**Pass:** cancelled work does not later publish a completed track or overwrite newer work. Cancelling pasted-lyrics correction preserves the existing track. Later work can proceed. Cancellation does not instantly interrupt an in-flight provider HTTP request.

#### 6. Degraded output — 05; controlled failure required

- [ ] If you have a degraded track, inspect its affected cue in both Transcript and overlay with the corresponding display setting enabled.

**Pass:** source survives; actual fallback shows `Word boundaries may be approximate.`; missing requested translation shows `Translation unavailable.`; missing requested pronunciation shows `Romanization unavailable.` A successful repair clears only the warning it resolves.

Normal clicks do not reliably produce malformed AI output. If you have no degraded track/fixture, mark this manual check NOT EXERCISED and use the automated regressions below. Turning translation off does not simulate missing requested translation. Ordinary Generate may reuse a degraded ready track; it is not a guaranteed repair action.

#### 7. Real chunk-boundary listening — 08

- [ ] Use a freshly processed clip of roughly five to eight minutes under default settings. Confirm it used at least three transcription chunks.
- [ ] Listen and read within five seconds on each side of at least two actual chunk boundaries. Include speech through a boundary and repeated lyrics if possible.
- [ ] Look for missing words, doubled words, changed order and timestamp jumps.

**Pass:** the inspected words match the audio, distinct repetitions survive and cue timing remains natural. Record every mismatch with its timestamp; a provider recognition error still needs investigation before attributing it to merging.

Default chunk planning uses `n = min(8, ceil(audioDurationSeconds / 120))` and equal divisions of the measured audio duration, starting at four minutes. For exactly six minutes, inspect approximately 2:00 and 4:00. Other durations/settings have different boundaries; do not assume every cut is at a multiple of two minutes. A quiet boundary provides little word-preservation evidence. Synthetic tests do not prove real recognition quality.

### Focused automated checks for hard-to-trigger cases

From PowerShell, run the existing suites below. They cover malformed output and warnings, useful cards/case edits, shared request limits and deadlines, mixed-tier leases, actual cancellation/reset guards, late recovery and boundary merging. Expect every test to pass; these checks do not validate the visible UI or production capacity.

```powershell
Set-Location C:\transcribed-subtitle-extension\app\backend
php artisan test --compact tests/Unit/AiRequestRunnerTest.php tests/Unit/CueEnrichmentServiceTest.php tests/Unit/LearningTokenOutputValidatorTest.php tests/Unit/EditedCueTest.php tests/Unit/SubtitlePartialTrackAssemblerTest.php tests/Unit/ScribeChunkPayloadMergerTest.php tests/Feature/LyricsCorrectionContinuationTest.php tests/Feature/SubtitleRuntimeTracingTest.php tests/Feature/SubtitleJobApiTest.php

Set-Location C:\transcribed-subtitle-extension\app\extension
npm test -- tests/background-review.test.ts tests/active-tracks.test.ts tests/api-response-guards.test.ts tests/overlay.test.ts tests/panel-transcript.test.ts
```

### Report back

For each failure: test number, video URL/job ID, cue text and timestamp, exact steps, expected/actual behavior, and whether it reproduces. For interrupted edits, include when you disconnected/navigated and whether reopening the same job recovered it. Add a screenshot if useful; omit credentials.

| Check | PASS / FAIL / NOT EXERCISED | Observation |
| --- | --- | --- |
| 1. Normal learning output | | |
| 2. Word cards | | |
| 3. Quick Fix | | |
| 4. Late recovery/navigation | | |
| 5. Cancellation | | |
| 6. Degraded output | | |
| 7. Real chunk boundaries | | |
| Focused automated checks | | |

Full evidence, outstanding runtime experiments and acceptance/merge rules remain in [delivery-and-testing.md#testing-handoff](delivery-and-testing.md#testing-handoff).

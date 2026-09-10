# Learning and editing

Created: 2026-09-09
Last updated: 2026-09-10

Use the [index](00-index.md) for shared execution rules, ownership and the old-number map. Historical package numbers below identify audit evidence; they are sections of these consolidated documents, not separate work plans. This consolidation does not authorize new implementation or experiments.

This document owns user-facing learning semantics, degraded output, useful word cards and correction/recovery behavior. Generation performance and loaded-extension delivery/lifecycle checks belong to [the pipeline](pipeline-speed-quality-and-reliability.md); existing reviews and manual acceptance live in [Delivery and testing](delivery-and-testing.md).

## Punjabi lyrics replacement failure (2026-09-10)

The user reported a failed full replacement for `M8vDwlHigJA`. Attempt `5cd85fa2-430c-4022-8e61-961cd9400307` failed in alignment: the first successful HTTP response was rejected with `cue_identity_mismatch`; its retry was rejected with `invalid_cue_text`. The existing 50-cue track was preserved. Both calls used low reasoning. This establishes malformed alignment output, not a provider outage or proof that low reasoning caused it.

Scope: split overlong reconstructed text on word or grapheme boundaries into cues of at most 84 code points. Subdivide only that original timing slot in proportion to text length, preserving its endpoints and neighboring slots; internal split times are estimates, not word-level acoustic alignment. Keep exact pasted-text consumption, partial-confirmation policy, attempt guards and atomic publication. Clarify copying timing-slot identity and omitting unused slots, and record only numeric rejection details. Use the existing Laravel AI provider and low reasoning; no new retry loop or model experiment. Regression coverage uses synthetic Punjabi and Thai text; the user's full lyrics are not committed or logged.

Evidence: an initial attempt to supply calculated boundary limits still produced 89- and 99-character cues in live retry `6561800c-ddf0-4f80-819a-b0e068a86065`. Removed that prompt-only approach and moved wrapping into server code. Retry `16859d21-5c27-4e92-b058-dabc5cbcb847` then exposed redundant separator/start-index errors. Full-replacement output now identifies the timing cue and each pasted segment's end index; the server owns consecutive start indexes, separators and cue indexes. Partial-enabled alignment retains the explicit source ranges/separators it needs. Reversed, repeated, missing and out-of-range ending boundaries still fail, so source consumption remains exact.

All 54 correction tests pass (237 assertions), including the smaller full-replacement response, full replacement after partial permission, Punjabi exact text, no second alignment call for wrapping, preserved timing endpoints, Thai graphemes, and rejection when a slot cannot contain positive-duration pieces. The 10 AI instruction/provider tests also pass (132 assertions). Formatting passes. The full repository check passes: contracts, 487 backend tests (3,346 assertions), 227 extension tests, TypeScript compile and extension build. Backend and all 31 workers were restarted with this fix; health returns HTTP 200.

Live attempt `ae3e4202-9f6e-462c-a30b-7832b5381c83` passed alignment on its first call and completed publication to track `ca77fabc-e356-425c-ba26-725f15134825`. The existing bounded analysis retries handled malformed derived batches. Verified all pasted non-whitespace characters in order, 52 nonempty cues at most 84 code points, unique IDs and sequential indexes, positive non-overlapping durations, and coverage of all 50 original timing slots with exact endpoints. Two slots were subdivided contiguously. Every cue has translation and romanization fields; this verifies presence, not linguistic accuracy. Expiry is unchanged and encrypted correction input/work state are cleared. The local backend is running the fix; production deployment and user playback acceptance are not claimed.

## Learning quality and editing

Former audit section 05.

Status: code reviewed, committed and checked; user acceptance pending
Owner: Brain / Lead for selected code delivery
Work mode: Brain / Worker
Type: Confirmed fallback behavior; product decisions and quality validation

### Goal

Learners can distinguish complete learning output from degraded enrichment, receive useful word cards, and correct source text without losing timing or identity safety.

### Source and evidence

[Review F07](../../../whole-project-review-2026-09-09.md:161): invalid analysis can echo source as translation, no-whitespace Latin can become letter tokens, and requested romanization can be absent while completion succeeds. [Opportunity 11](../../../whole-project-review-2026-09-09.md:245) notes any-field card completeness, case-only edit rejection, and synchronous quick-fix timeout risk; browser incidence is unverified.

Coverage: F07; opportunity 11; E05; linguistic-quality and correction notes from report sections 4–5.

### Dependencies

The [source text and token fidelity](pipeline-speed-quality-and-reliability.md#source-text-and-token-fidelity) section establishes source preservation; the [provider compatibility and retries](pipeline-speed-quality-and-reliability.md#provider-compatibility-and-retries) section establishes transient error handling. Define semantics before the [ai latency and linguistic quality](pipeline-speed-quality-and-reliability.md#ai-latency-and-linguistic-quality) section evaluates model quality.

### Scope

Explicit degraded translation/tokenization/romanization behavior, useful minimum card fields, bounded repairs, spelling/case-only editing policy and late quick-fix outcomes. Preserve native source display and atomic track/cue replacement.

Out of scope: Selecting model winners, generic chat/coaching features, automatically failing every degraded track or adding forced alignment without a separate validated decision.

### Relevant files and context

- [app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php](../../../../app/backend/app/Services/TranslationAnalysis/LaravelAiTranslationAnalysisProvider.php)
- [app/backend/app/Services/TranslationAnalysis/LearningTokenEnrichmentService.php](../../../../app/backend/app/Services/TranslationAnalysis/LearningTokenEnrichmentService.php)
- [app/backend/app/Services/Subtitles/LyricsCorrectionService.php](../../../../app/backend/app/Services/Subtitles/LyricsCorrectionService.php)
- [app/backend/app/Services/Subtitles/TimestampedSubtitleTrackGenerator.php](../../../../app/backend/app/Services/Subtitles/TimestampedSubtitleTrackGenerator.php)
- [app/extension/utils/api.ts](../../../../app/extension/utils/api.ts)
- [app/extension/utils/panel/transcript.ts](../../../../app/extension/utils/panel/transcript.ts)

### Implementation or investigation steps

- [ ] Record the intended user-facing fallback and minimum useful card contract. Distinguish incomplete derived work from a failed whole track; do not silently substitute the current implementation for product intent.
- [ ] Preserve a single spaced-script word during deterministic fallback and let transient exceptions reach retries. Add durable quality state only for behavior the product displays or operates.
- [ ] Make absent translation/romanization visible and boundedly repairable. Carry any schema changes through resources, generated types, runtime guards and extension rendering.
- [ ] Test incomplete metadata, repeated ambiguous words and case-only edits. The [caching retention and data efficiency](pipeline-speed-quality-and-reliability.md#caching-retention-and-data-efficiency) section owns cache identity; this package defines the semantic expected result.
- [ ] Reproduce long quick-fix responses, timeout followed by late commit, stale edit identity and full-lyrics partial acceptance. Use durable correction operations only if the existing flow cannot meet recovery semantics; coordinate browser acceptance with the [browser delivery and lifecycle](pipeline-speed-quality-and-reliability.md#browser-delivery-and-lifecycle) section.

### Acceptance criteria

- [ ] Source remains readable when enrichment degrades and source text is not represented as a completed translation.
- [ ] Missing requested features have explicit semantics; retry count/time remain bounded.
- [ ] Minimum card completeness and case-only correction behavior are documented and tested.
- [ ] Corrections preserve current-run/track/cue ownership, timing, atomic publication and concurrent token patches.
- [ ] Late quick-fix results have a demonstrated recovery behavior; pasted lyrics are not claimed to repair acoustic alignment.

### Validation and rollout

E05 uses malformed/empty/refusal-like outputs and adversarial source instructions with offline fakes first; add bilingual judgments for linguistic quality. Run relevant contract/backend/extension tests and the repository check. The [browser delivery and lifecycle](pipeline-speed-quality-and-reliability.md#browser-delivery-and-lifecycle) section records actual browser lifecycle evidence.

### Progress and decisions

- 2026-09-09: Code scope selected in the combined [05/06/08 delivery](delivery-and-testing.md#delivery-scope-and-decisions). The [consolidated review](delivery-and-testing.md#independent-review) records final behavior, decisions and evidence. Degraded warnings are visible; absent translation is empty with a warning; minimum useful card means nonblank translation or gloss. User UI/linguistic acceptance remains pending. Earlier product language requiring whole-job failure is superseded by this explicit source-preserving degraded policy.

### Completion notes

Code is delivered on the combined branch through `088d24a` (cards/case), `6e67508` and `ac6c310` (degraded output/source matching), and `2ca310f` (quick-fix recovery). Independent reviews resolved warning accumulation, contract-invalid lyrics publication, stale repaired warnings, mixed-script/case/source checks, and late extension ownership/persistence races. See the [consolidated review](delivery-and-testing.md#independent-review). Most recent focused evidence: 142 backend tests/527 assertions for the warning/correction slice, 20 validator tests/37 assertions after source repair, 49 extension recovery tests and TypeScript compile.

The combined delivery passed its final integrated harness: 516 backend tests / 3,336 assertions, 249 extension tests, contracts, compile and build. The exact reviewed revision is in delivery-and-testing.md#testing-handoff. User UI, browser lifecycle and bilingual quality acceptance remain outstanding. There is no new automatic repair loop: existing Quick Fix or pasted-lyrics rebuilding can resolve derived warnings when successful; ordinary Generate can reuse a degraded ready track and is not a guaranteed unchanged-track repair. No acoustic alignment, model or effort change was introduced. Processing versions are updated centrally in `51e75fe`. Experiments remain deferred; acceptance/merge approval is not inferred from code review or tests.

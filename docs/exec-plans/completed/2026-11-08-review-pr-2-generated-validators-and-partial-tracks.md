# Plan: Review PR 2 generated validators and partial tracks

Read main checkout guidance because ignored harness files are absent in worktrees. Applied Laravel best practices and subtitle pipeline skills. Boost is not installed in the locked dependencies; Context7 supplied current Ajv and Laravel docs.

- Generate standalone schema validators at contracts build time. Keep relational preview ownership check.
- Build previews on read with one artifact query and no job lock or cache write.
- Try bounded SSE with polling fallback. Keep persisted operation recovery for worker suspension.
- Validate with the full harness and fake providers only. No paid calls.

Decision: retain polling fallback because MV3 can suspend a stream. SSE is an optimization, not durable ownership. No config added.

Complete. Full local harness passed. No paid provider calls or real generations.

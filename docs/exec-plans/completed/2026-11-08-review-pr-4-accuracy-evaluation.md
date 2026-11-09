# Plan: Review PR 4 accuracy evaluation

Add two synthetic gold references, normalized WER/CER, existing boundary metric, and an offline saved-output command. Optional judge requires --judge and is never run in tests. Use Laravel AI SDK structured output and fake agents. No live generations or paid comparisons. Validate missing data and saved/cache scoring with fixtures.

Complete. Full harness passed: 706 backend tests, 428 extension tests. The optional judge was not run.

# Subtitle accuracy evaluation

Run `php artisan subtitles:eval --gold=tests/Fixtures/gold/synthetic.json --json --out=report.json`. This reads saved tracks. Add `--cached` to read cached Scribe transcripts, or `--job=<public job UUID>` to select one saved generation. It never generates subtitles. Missing output returns exit code 1. Without `--json` it prints a short table followed by JSON.

The bundled examples are tiny synthetic text, not measured YouTube references. Add a JSON array entry with `videoId` (11 characters), `language` (catalog code), and a human-checked `transcript`. Optional `tokens` is a list of reference lexical tokens in order. Optional `translation` is a checked target-language reference; set optional `targetLanguage` to filter saved tracks for that reference. Do not add private or unlicensed transcripts.

WER uses word edit distance. Japanese, Chinese, Thai, Lao and Burmese use character edit distance. Text uses Unicode NFKC, lowercase, punctuation removal and collapsed whitespace; CER also removes whitespace. Rates may exceed 1 when insertions exceed reference length. Token-boundary F1 reuses TokenizationBoundaryMetric and reports unlocatable predictions. Cache-only results have no token score.

Translation judging is off. `--judge` explicitly allows paid calls to the configured direct AI provider. It scores reference/candidate meaning from 0 to 100; treat it as advisory. Tests never run this option. Review model/key settings before enabling it. Local CLI providers are not supported for this optional judge.

To compare reasoning effort, analysis model or Scribe settings, save outputs for the same gold entries under each setting. Record the exact settings and processing version with the reports. Score saved job UUIDs or cached transcripts, then compare pooled edit counts divided by pooled reference units; do not average language rates blindly. Saved reports identify job, track, provider/model or transcription cache model. Change one setting at a time. The command does not change settings or launch paid comparisons. Full-context translation and captions should be compared on the same references after the next processing-version change.

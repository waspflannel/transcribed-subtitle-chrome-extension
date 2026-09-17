# Website and extension localization review

Branch: `codex/website-extension-localization`.

## Automated checks

- `scripts/agent/check.ps1`: passed documentation lint, contract validation/build, 689 backend tests / 37,044 assertions, 355 extension tests, TypeScript, and Chrome production build.
- Nine environment-dependent PostgreSQL/Redis integration tests were skipped by the normal backend test profile. This change does not alter persistence or worker behavior.
- `php vendor/bin/pint --dirty --format agent`: passed after formatting.
- `npm run build:firefox`: passed. Existing Firefox distribution warnings concern extension ID and data-collection permissions; this work does not establish Firefox store readiness.
- Both production bundles contain all nine native `_locales` catalogs and use the English default manifest locale. Total uncompressed extension size is about 1.37 MB with bundled translations.
- `git diff --check`: passed.

Localization tests cover exact message-key parity, nonempty translations, preserved placeholders, escaped markup, browser/cookie/query priority, invalid locale inputs, public/auth routes, web/API locale isolation, independent study settings, unchanged API routes, saved-generation labels, retained transcript drafts and active cues, and untouched generated learning content.

## Browser evidence

Used agent-browser against the existing local Laravel server at `127.0.0.1:8001` and the actual extension side-panel sources bundled by [the isolated fixture](../2026-09-15-remediation/extension-fixture.cjs) at `127.0.0.1:8772`. The fixture substitutes browser transport and sample content; it does not exercise an installed extension or paid generation.

- [Japanese website, desktop](website-japanese.png): translated navigation, hero, and guide links at 1440 px.
- [German website, mobile](website-german-mobile.png): visible language choice and wrapped copy at 390 px. Also checked German at 1280 px; neither viewport had horizontal document overflow.
- Website switcher changed Japanese registration to Korean while preserving `plan=base`; a later visit to `/login` retained Korean through the preference cookie.
- [German extension](extension-german.png): language selection, generation settings, and model guidance at 360 px without document overflow. Review corrected inline spacing and unequal language-column widths.
- [Chinese transcript](extension-chinese-transcript.png): interface controls and saved-generation labels changed while Arabic source text, romanization, and the English learning translation stayed intact.
- [Japanese study settings](extension-japanese-study.png): translated native selects, toggles, and timing controls.

## Limits

The eight additional catalogs are AI-drafted, with structural validation and sampled reading. They have not received native-speaker editorial certification. Existing guide images still show English UI, with translated captions and instructions. External service pages and transactional emails are outside this interface rollout. These checks do not replace installed-extension testing on real YouTube videos or release review.

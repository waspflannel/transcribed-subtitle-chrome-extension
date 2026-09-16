# Website guide review evidence

Branch: `codex/how-to-use-website-cleanup`.

## Automated validation

- `scripts/agent/check.ps1` passed: documentation lint, contract checks/build, 684 backend tests (5,443 assertions; 9 skipped), 342 extension tests across 32 files, TypeScript compile, and production extension build.
- Focused website and job tests: 37 passed, 388 assertions.
- Laravel Pint and `node --check public/js/site-interactions.js` passed.
- `git diff --check` passed.

## Browser checks

- Homepage guide button and topic links navigate to the public guide.
- Guide section links resolve and the desktop sidebar marks the current section.
- Mobile contents collapse/reopen with pointer and keyboard. A section link lands below the fixed header.
- Guide has no horizontal overflow at 320px and 390px. The shortcuts table wraps inside the reading column.
- Homepage and support page fit the 390px viewport; guide checked at 1440px desktop.
- Job detail checked at 390px and 1920px. The content is centered with fixed gutters, metrics form a readable grid, and actions remain reachable.
- Job screenshots use a real controller/view render with disposable in-memory test data; they do not expose a runtime account. Actual dashboard-link access and ownership/privacy boundaries are covered by `WebSubtitleJobSupportTest`.
- Browser logs contained only skipped native page-transition events from rapid navigation, with no guide-script errors.

## Screenshots

- `home-desktop.png`, `home-mobile.png`: replacement guide introduction and hero button.
- `guide-desktop.png`: lyrics instructions, sidebar and active section.
- `guide-mobile.png`: responsive keyboard shortcut table.
- `job-desktop.png`, `job-mobile.png`: repaired Support ID detail layout.

## Content handoff

The guide is `app/backend/resources/views/marketing/how-to-use.blade.php`. Add real screenshots/videos inside the relevant sections using `figure` and `figcaption`; media styles are in `public/css/site/guide.css`. No fake media or empty players are shown. The existing `CHROME_EXTENSION_URL` enables the store button; both install methods stay documented when it is absent. ZIP distribution remains managed separately.

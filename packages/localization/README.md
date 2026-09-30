# Interface localization

`locales.json` is the shared list of interface languages and their native names. It is separate from `packages/contracts/languages.json`, which describes the languages users can study.

- `website/*.json`: Laravel JSON messages for public pages, authentication, the account dashboard, validation, and metadata.
- `extension/*.json`: bundled messages for panel, overlay, progress, errors, and language labels. English is also the runtime fallback.
- `app/extension/public/_locales/*/messages.json`: native browser name and description. Browser locale directory names use underscores, such as `pt_BR` and `zh_CN`.

## Editing copy

Use complete English phrases as keys. Add each new phrase to all nine catalogs. Preserve `{name}` extension variables, `:name` Laravel variables, and `:slot1:` markup placeholders exactly. Translate phrases around those variables and keep paired markup placeholders correctly nested. Translation strings must not contain HTML; the website escapes translations before inserting its own markup and escaped values.

Use `__('...')` in website templates and controllers. Use `t('...')` for dynamic extension copy and `data-i18n` or `data-i18n-title/aria-label/placeholder` for static panel copy. Never translate API paths, selectors, identifiers, video titles, generated subtitles, or user drafts. Do not resolve translations when modules first load: the interface language can change during the session.

Public website language comes from its URL: English is unprefixed, and other locales use the mapping in `app/backend/config/localization.php`. Public `?lang=` links redirect to canonical language paths; cookies and browser preferences cannot change the language at that address. There are no auth/account pages; the private instance website keeps canonical language paths and its preference cookie. Public Simplified Chinese tags use `zh-Hans`, while shared catalog and extension settings keep `zh-CN`. The extension saves `interfaceLocale` independently of source/target languages; `auto` follows browser language. A locale change should refresh cached UI labels while retaining drafts and selected tracks.

## Validation

Run `scripts/agent/check.ps1` from the repository root. `WebsiteLocalizationTest` checks catalog parity, placeholder preservation, instance pages, canonical locale redirects, and placeholder integrity. Extension tests cover locale matching, marked copy, saved-generation labels, transcript drafts, overlay content, and unchanged API requests in all locales. Build Firefox with `npm run build:firefox` from `app/extension`.

The initial non-English catalogs are AI-drafted and structurally checked. Native-speaker editorial review remains useful, especially for provider-privacy and instance-setup wording. The retained installation screenshot shows the English Chrome interface; their surrounding instructions and captions are translated. External provider pages are outside these interface catalogs.

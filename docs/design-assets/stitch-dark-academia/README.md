# Stitch Dark Academia Website References

Stitch project: `projects/11285798713880801131`

Design system asset: `assets/5ee04fe765404b7fb7e1d34e23d44d50` (`Obsidian Scriptorium`)

Uploaded design brief screen:

- `DESIGN.md`: `projects/11285798713880801131/screens/2546209766202785116`

Tooling notes:

- `upload_design_md` succeeded on 2026-06-05 and created the `DESIGN.md` screen instance.
- `create_design_system_from_design_md` succeeded on 2026-06-05 and created the `Obsidian Scriptorium` design system asset.
- Screen-generation tools for homepage, public templates, auth, dashboard, and job detail screens were not exposed in this Codex session. The implementation uses the uploaded Stitch brief and generated design-system output as the accepted design direction.

Production image assets copied into the Laravel public tree:

- Hero scholarly language desk: `app/backend/public/img/marketing/dark-academia/hero-language-desk.png`
- Product video poster fallback: `app/backend/public/img/marketing/dark-academia/product-video-poster.png`
- Perk tile for missing captions: `app/backend/public/img/marketing/dark-academia/perk-missing-captions.png`
- Perk tile for translation layers: `app/backend/public/img/marketing/dark-academia/perk-translation-layer.png`
- Perk tile for word cards: `app/backend/public/img/marketing/dark-academia/perk-word-cards.png`
- Final reading room CTA: `app/backend/public/img/marketing/dark-academia/final-reading-room.png`

Generated image source directory:

- `C:\Users\jaden\.codex\generated_images\019e9617-0560-7ff1-a12d-a382d7803250`

Rejected generated variants:

- The first final CTA candidate included a bust/statue.
- The second final CTA candidate included a visible laptop brand mark.
- The first translation-layer tile included legible script-like marks.

The accepted assets are original, repo-owned copies. Production Blade and CSS do not hotlink Stitch, Hermes, or generated-image temp paths.

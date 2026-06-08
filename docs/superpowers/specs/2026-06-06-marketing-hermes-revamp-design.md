# Marketing Site Revamp - Hermes-Inspired, Warm Dark-Academia

**Date:** 2026-06-06
**Status:** Implemented and verified (2026-06-06) - built directly per user request (no separate plan). All 8 marketing pages render 200 with the warm palette, shared shell, and motion system; verified via local serve + screenshots.
**Scope:** All marketing pages (`resources/views/marketing/*`) + shared layout, CSS, JS.

## Goal

Revamp the marketing frontend to borrow the structure, polish, and motion of
`https://hermes-agent.nousresearch.com/desktop` while keeping our existing
**dark-academia** identity. Hermes contributes *layout patterns and motion*, not
color. All images keep their current files and placements; all copy stays as-is
(filler is fine).

## Context (current state)

- `public/css/site.css` contains the original dark-academia system plus the scoped
  home landing override:
  - **Original dark-academia**: warm brass/gold
    (`#b88a3b` / `#d4aa57`), oxblood (`#7f1d1d`), parchment "paper" sections,
    Cormorant Garamond + IBM Plex Mono + Inter. Used by the marketing/account shell.
  - **Hermes landing override** (`.hermes-landing-body` / `.da-*` / `.feature-*`):
    remaps Hermes structure and motion into the warm palette. Used by **home only**.
- Layouts: `layouts.site` is the canonical shared shell with sticky `.site-header`,
  full nav, footer, dotted-grain overlay, and `site-interactions.js`. The old
  `layouts.hermes-desktop` path is not present.
- Both layouts already load the same three fonts.
- Motion is centralized in `public/js/site-interactions.js`: scroll-aware navbar and
  `IntersectionObserver` fade-up for `[data-reveal]` elements. The removed
  text-scramble path is intentionally not part of the current implementation.

**Core tension resolved:** "more Hermes" + "keep dark-academia" conflict on color
(Hermes is cold/electric; dark-academia is warm/candlelit). Decision: borrow Hermes
**structure + motion**, render it in the **warm brass palette**.

## Decisions (confirmed with user)

1. **Accent color:** Warm brass/gold everywhere. Remove all electric blue.
2. **Scope:** All marketing pages.
3. **Intensity:** Polished evolution - keep each page's section structure; elevate
   motion, spacing, typography, cohesion. (Home keeps its 6-card feature grid.)
4. **Architecture:** One unified marketing shell (Approach A) - `layouts.site`
   becomes the single canonical layout; home becomes content-only.
5. **Pricing:** Highlight one "recommended" tier.

## Design

### 1. Unified warm palette (kill the blue)
One token set built on the existing dark-academia vars. The `--hermes-*` variables
are **remapped to warm values** so the entire home page recolors in one move:

| `--hermes-*` token        | New warm value (source)                     |
|---------------------------|---------------------------------------------|
| `--hermes-bg`             | `--black` `#050505`                         |
| `--hermes-surface-1/2/3`  | `--obsidian` / `--surface` / `--surface-high` |
| `--hermes-border`         | warm line `rgba(184,138,59,.28)` / `--line-dark` |
| `--hermes-accent`         | `--brass-bright` `#d4aa57`                   |
| `--hermes-accent-hover`   | lighter brass (`#e2bd72`)                    |
| `--hermes-text-primary`   | `--ivory` `#f4ead7`                          |
| `--hermes-text-secondary` | `--ivory-muted` `#d8c7a8`                    |
| `--hermes-text-muted`     | `--paper-muted` `#c8b99e`                    |
| `--hermes-badge-bg/border`| warm brass-tint bg + border                 |

Then replace every hardcoded blue `rgba(74,158,255,...)` (hero radial, button glows,
card/preview shadows, badge dot, focus ambient) with brass
equivalents (`rgba(184,138,59,...)` / `rgba(212,170,87,...)`). Parchment "paper"
sections stay as the warm light counterpoint.

Also: make `.hermes-landing-body` base typography match the rest - headings render
in **Cormorant Garamond** (currently overridden to Geist sans), labels stay mono.

### 2. Unified shell (every page)
A single fixed, scroll-aware navbar: transparent over the hero, transitioning to
frosted + brass-hairline border on scroll (Hermes's nav behavior, warm styling).
Shared footer, shared dotted-grain overlay, shared JS - all moved into the canonical
layout. Consistent nav links + auth-aware Sign in / Get Started actions. Home loses
its inline nav/footer and uses the shared shell.

### 3. Motion system (site-wide)
- **Scroll-reveal:** generalized `[data-reveal]` hook - fade + rise on entry via
  `IntersectionObserver`, reset to `.is-visible`. Optional **stagger** for
  grids/lists/timelines (per-item delay).
- **Navbar:** transparent to frosted on scroll, all pages.
- **Hero:** staggered line-by-line headline entrance; slow CSS float on hero art.
- **Hover:** buttons lift + warm glow; cards lift + brass border; feature images
  scale + brighten; links shift to brass with growing underline.
- **CTA animation:** text-scramble is removed; current CTA motion is button lift and
  warm glow only.
- `prefers-reduced-motion`: all of the above disabled/neutralized.

### 4. Per-page polish (structure unchanged)
- **Home:** hero (warm radial glow, floating art, staggered headline), preview
  frame, 6-card feature grid (mono numbers, brass hairline headers, hover), warm
  final CTA.
- **How-it-works:** timeline gains a connected vertical brass line + staggered steps.
- **Pricing:** cards get hover-lift; one tier flagged "recommended" (brass ring +
  badge).
- **FAQ:** `<details>` get smooth expand + brass marker.
- **Languages / Support / Privacy / Terms:** consistent reveal + spacing rhythm.
- All `page-hero`s get the editorial eyebrow to serif H1 reveal treatment.

### 5. Typography & spacing rhythm
Cormorant Garamond display / IBM Plex Mono micro-labels / Inter body, applied
consistently. Hermes-like generous section padding and a unified vertical rhythm.

## File-by-file changes

- **`public/css/site.css`**
  - Remap `--hermes-*` tokens to warm values; replace all blue `rgba(74,158,255,...)`.
  - Warm `.hermes-landing-body` base typography (serif headings).
  - Converge navbar styling to one scroll-aware brass nav used by the shared layout.
  - Add `[data-reveal]` motion utilities (base + `.is-visible` + stagger + reduced-motion).
  - Per-page polish: timeline line, pricing recommended tier, FAQ expand/marker, hovers.
  - Remove dead `.hermes-feature-row`/standalone Hermes layout styles and keep the
    live `.feature-row` landing section.
- **`public/js/site-interactions.js`** (shared marketing JS)
  - Keep navbar scroll toggle (target unified nav id).
  - Generalize `IntersectionObserver` to `[data-reveal]`.
- **`resources/views/layouts/site.blade.php`** (canonical shell)
  - Add dotted-grain overlay, scroll-aware navbar markup (id for JS), include the JS,
    unified body class.
- **`resources/views/marketing/home.blade.php`**
  - `@extends('layouts.site')`; remove inline navbar + footer; keep hero/preview/
    features/CTA; add `data-reveal` hooks.
- **`resources/views/layouts/hermes-desktop.blade.php`** - retired/not present.
- **`resources/views/marketing/{pricing,how-it-works,faq,support,privacy,terms,languages}.blade.php`**
  - Add `data-reveal` hooks (+ stagger) to sections/cards/timeline/faq items.
  - Pricing: flag recommended tier. No structural/content changes.

## Out of scope (unchanged)
- Copy/content (filler stays), images (same files + placements).
- App / auth / dashboard pages - marketing only.

## Verification
- Serve the Laravel app locally; load each marketing page (home, how-it-works,
  languages, pricing, faq, support, privacy, terms).
- Confirm: warm palette throughout (no blue anywhere), navbar transparent to frosted on
  scroll, scroll-reveals fire once on entry, hovers work, layout intact at
  desktop + mobile breakpoints, and `prefers-reduced-motion` neutralizes motion.

## Update - iteration 2 (2026-06-06): crimson + clean sans + Hermes flow

User feedback after v1: liked the dark + structure but **rejected the gold** and wanted
the page to **flow/connect like Hermes** with **matching buttons**. Pivoted:

- **Palette:** gold/brass to **deep crimson** (`--brass-bright` value now `#d83b3b`,
  hover `#e85d5d`; all `rgba(216,59,59,...)` glows). Dark surfaces neutralized from
  warm-brown to neutral grays (`#0a0a0a`/`#141414`/`#1e1e1e`); text to near-white/gray.
  Borders neutral (`rgba(255,255,255,.1)`).
- **Type:** Cormorant serif to **Geist** clean sans (via `--font-display`) for all
  headings; weights bumped 500 to 600. Inter body, IBM Plex Mono labels retained.
- **Buttons:** Hermes style - rounded 8px, **filled crimson + white text + hover
  glow**, sentence-case sans (was ivory uppercase mono). Hero CTA scramble removed.
- **Flow:** home 6-card feature grid to **alternating full-width feature rows**
  (`.feature-row` / `.feature-row-inner`, `:nth-child(even)` flips, alternating bg,
  hairline dividers) for Hermes-like vertical rhythm.
- **Legal pages:** cream parchment `.legal-copy` to dark surface (theme cohesion).

Verified via computed styles + HTTP 200 on all pages (preview screenshot tool was
hanging on the Geist web-font load in the headless renderer; JS eval + curl confirmed).

import type { ExtensionSettings } from './settings-model';
import { escapeHtml } from './html';
import type { LearningToken, SubtitleCue } from './contracts';
import type { SubtitleState } from './messages';
import type { YoutubePageInfo } from './youtube';

export interface OverlayRenderState {
  page: YoutubePageInfo;
  subtitleState: SubtitleState;
  settings: ExtensionSettings;
  activeCue?: SubtitleCue | null;
}

interface OverlayInteractionState {
  pinnedTokenIndex: number | null;
}

const EMPTY_INTERACTION: OverlayInteractionState = {
  pinnedTokenIndex: null,
};

export class OverlayShell {
  private host: HTMLDivElement | null = null;
  private content: HTMLDivElement | null = null;
  private renderedHtml: string | null = null;
  private currentState: OverlayRenderState | null = null;
  private currentCueId: string | null = null;
  private pinnedTokenIndex: number | null = null;

  public constructor(private readonly documentRef: Document = document) {}

  public update(state: OverlayRenderState): void {
    if (!this.host || !this.content) {
      this.mount();
    }

    this.host!.dataset.position = state.settings.overlayPosition;
    this.host!.style.display = state.settings.overlayVisible ? 'block' : 'none';
    this.currentState = state;

    const activeCueId = state.subtitleState.type === 'ready' ? state.activeCue?.cueId ?? null : null;

    if (activeCueId !== this.currentCueId) {
      this.currentCueId = activeCueId;
      this.pinnedTokenIndex = null;
    }

    this.render();
  }

  public unmount(): void {
    this.host?.remove();
    this.host = null;
    this.content = null;
    this.renderedHtml = null;
    this.currentState = null;
    this.currentCueId = null;
    this.pinnedTokenIndex = null;
  }

  private render(): void {
    if (!this.content || !this.currentState) {
      return;
    }

    const html = renderOverlayContent(this.currentState, {
      pinnedTokenIndex: this.pinnedTokenIndex,
    });

    if (html !== this.renderedHtml) {
      this.content.innerHTML = html;
      this.renderedHtml = html;
      this.bindTokenInteractions();
    }
  }

  private bindTokenInteractions(): void {
    if (!this.content) {
      return;
    }

    for (const button of this.content.querySelectorAll<HTMLButtonElement>('[data-token-index]')) {
      const tokenIndex = Number(button.dataset.tokenIndex);

      if (!Number.isInteger(tokenIndex)) {
        continue;
      }

      button.addEventListener('click', () => {
        this.pinnedTokenIndex = this.pinnedTokenIndex === tokenIndex ? null : tokenIndex;
        this.render();
      });
    }

    this.content.querySelector<HTMLButtonElement>('[data-close-token-detail]')?.addEventListener('click', () => {
      this.pinnedTokenIndex = null;
      this.render();
    });
  }

  private mount(): void {
    const existingHost = this.documentRef.querySelector<HTMLDivElement>('#tse-overlay-host');

    if (existingHost?.shadowRoot) {
      this.host = existingHost;
      this.content = existingHost.shadowRoot.querySelector<HTMLDivElement>('[data-overlay-content]')!;
      this.renderedHtml = null;

      return;
    }

    const host = this.documentRef.createElement('div');
    host.id = 'tse-overlay-host';
    host.setAttribute('aria-live', 'polite');

    const shadowRoot = host.attachShadow({ mode: 'open' });
    shadowRoot.innerHTML = `
      <style>
        :host {
          all: initial;
          bottom: 84px;
          left: 16px;
          pointer-events: none;
          position: fixed;
          right: 16px;
          top: auto;
          width: auto;
          z-index: 2147483647;
        }

        :host([data-position="top"]) {
          bottom: auto;
          top: 72px;
        }

        :host([data-position="compact"]) {
          left: auto;
          right: 16px;
          width: min(390px, calc(100vw - 32px));
        }

        .shell {
          background: rgba(16, 24, 40, 0.93);
          border: 1px solid rgba(255, 255, 255, 0.16);
          border-radius: 8px;
          box-shadow: 0 12px 36px rgba(0, 0, 0, 0.28);
          color: #f8fafc;
          display: grid;
          font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
          gap: 7px;
          line-height: 1.35;
          margin: 0 auto;
          max-width: min(820px, calc(100vw - 32px));
          padding: 12px 14px;
          pointer-events: auto;
        }

        .eyebrow {
          color: #a7f3d0;
          font-size: 11px;
          font-weight: 700;
          letter-spacing: 0;
          text-transform: uppercase;
        }

        .title {
          font-size: 15px;
          font-weight: 700;
        }

        .detail {
          color: #cbd5e1;
          font-size: 13px;
        }

        .meta {
          color: #94a3b8;
          display: flex;
          flex-wrap: wrap;
          font-size: 12px;
          gap: 8px;
        }

        .line {
          align-items: center;
          display: flex;
          flex-wrap: wrap;
          font-size: 20px;
          font-weight: 750;
          gap: 6px;
        }

        .source-text {
          overflow-wrap: anywhere;
        }

        .translation {
          color: #e2e8f0;
          font-size: 14px;
        }

        .cue-romanization {
          color: #bae6fd;
          font-size: 13px;
        }

        .token {
          align-items: center;
          background: rgba(255, 255, 255, 0.08);
          border: 1px solid rgba(255, 255, 255, 0.14);
          border-radius: 6px;
          color: inherit;
          cursor: pointer;
          display: inline-grid;
          font: inherit;
          gap: 2px;
          line-height: 1.15;
          min-height: 36px;
          padding: 5px 7px;
          position: relative;
          text-align: center;
        }

        .token:hover,
        .token:focus-visible,
        .token[aria-pressed="true"] {
          background: rgba(20, 184, 166, 0.22);
          border-color: rgba(94, 234, 212, 0.72);
          outline: none;
        }

        .token-text {
          font-size: 18px;
          font-weight: 750;
        }

        .token-extra {
          color: #cbd5e1;
          font-size: 11px;
          font-weight: 600;
        }

        .token-inline-preview,
        .token-detail {
          background: rgba(15, 23, 42, 0.88);
          border: 1px solid rgba(148, 163, 184, 0.28);
          border-radius: 8px;
          color: #e2e8f0;
          display: grid;
          font-size: 13px;
          gap: 5px;
          padding: 8px 10px;
        }

        .token-inline-preview {
          bottom: calc(100% + 8px);
          box-shadow: 0 10px 28px rgba(0, 0, 0, 0.26);
          display: none;
          left: 50%;
          min-width: 150px;
          position: absolute;
          transform: translateX(-50%);
          z-index: 1;
        }

        .token:hover .token-inline-preview,
        .token:focus-visible .token-inline-preview {
          display: grid;
        }

        .token-detail-header {
          align-items: center;
          display: flex;
          gap: 10px;
          justify-content: space-between;
        }

        .token-detail-title {
          color: #f8fafc;
          font-size: 14px;
          font-weight: 750;
        }

        .icon-button {
          align-items: center;
          background: rgba(255, 255, 255, 0.08);
          border: 1px solid rgba(255, 255, 255, 0.16);
          border-radius: 6px;
          color: #e2e8f0;
          cursor: pointer;
          display: inline-flex;
          font-size: 14px;
          height: 28px;
          justify-content: center;
          line-height: 1;
          padding: 0;
          width: 28px;
        }

        .token-fields {
          display: grid;
          gap: 4px;
          grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        }

        .field {
          display: grid;
          gap: 1px;
        }

        .field-label {
          color: #94a3b8;
          font-size: 11px;
          font-weight: 700;
        }

        .field-value {
          color: #f8fafc;
          overflow-wrap: anywhere;
        }
      </style>
      <div data-overlay-content></div>
    `;

    this.host = host;
    this.content = shadowRoot.querySelector<HTMLDivElement>('[data-overlay-content]')!;
    (this.documentRef.body ?? this.documentRef.documentElement).append(host);
  }
}

export function renderOverlayContent(
  state: OverlayRenderState,
  interaction: OverlayInteractionState = EMPTY_INTERACTION,
): string {
  if (!state.page.supported) {
    return renderShell({
      eyebrow: 'AI subtitles',
      title: 'Unsupported page',
      detail:
        state.page.reason === 'missing_video_id' || state.page.reason === 'invalid_video_id'
          ? 'The current YouTube watch URL has no valid video ID.'
          : 'YouTube watch page required.',
      meta: [],
    });
  }

  if (state.subtitleState.type === 'ready') {
    const cue = state.activeCue;

    if (!cue) {
      return '';
    }

    const cueRomanization =
      state.settings.showRomanization && cue.romanization
        ? `<div class="cue-romanization">${escapeHtml(cue.romanization)}</div>`
        : '';

    return `
      <section class="shell" role="status">
        <div class="eyebrow">AI subtitles</div>
        <div class="line" lang="${state.subtitleState.track.sourceLanguage === 'auto' ? 'und' : state.subtitleState.track.sourceLanguage}">${renderSourceLine(
          cue,
          state.settings,
          interaction,
        )}</div>
        ${cueRomanization}
        <div class="translation">${escapeHtml(cue.translatedText)}</div>
        ${renderTokenInteraction(cue, state.settings, interaction)}
        <div class="meta"><span>Video ${escapeHtml(state.page.videoId)}</span><span>Transcribed track</span></div>
      </section>
    `;
  }

  if (state.subtitleState.type === 'error') {
    return renderShell({
      eyebrow: 'AI subtitles',
      title: 'Subtitle generation failed',
      detail: state.subtitleState.message,
      meta: [`Video ${state.page.videoId}`],
    });
  }

  if (state.subtitleState.type === 'loading') {
    return renderShell({
      eyebrow: 'AI subtitles',
      title: 'Generating subtitles',
      detail: state.subtitleState.message,
      meta: [`Video ${state.page.videoId}`],
    });
  }

  return renderShell({
    eyebrow: 'AI subtitles',
    title: 'No generated track',
    detail: 'This video does not have a generated subtitle track yet.',
    meta: [`Video ${state.page.videoId}`],
  });
}

function renderSourceLine(
  cue: SubtitleCue,
  settings: ExtensionSettings,
  interaction: OverlayInteractionState,
): string {
  if (cue.tokens.length === 0) {
    return `<span class="source-text">${escapeHtml(cue.sourceText)}</span>`;
  }

  return cue.tokens
    .map((token) => {
      const extras = [
        settings.showRomanization ? token.romanization : null,
        settings.showGloss ? token.gloss ?? token.translation : null,
      ]
        .filter((value): value is string => typeof value === 'string' && value.trim() !== '')
        .map((value) => `<span class="token-extra">${escapeHtml(value)}</span>`)
        .join('');

      return `<button class="token" type="button" data-token-index="${token.index}" aria-pressed="${
        interaction.pinnedTokenIndex === token.index ? 'true' : 'false'
      }"><span class="token-text">${escapeHtml(token.text)}</span>${extras}${renderTokenPreview(token, settings)}</button>`;
    })
    .join('');
}

function renderTokenInteraction(
  cue: SubtitleCue,
  settings: ExtensionSettings,
  interaction: OverlayInteractionState,
): string {
  const selectedIndex = interaction.pinnedTokenIndex;
  const token = cue.tokens.find((candidate) => candidate.index === selectedIndex);

  if (!token) {
    return '';
  }

  const rows = tokenDetailRows(token, settings)
    .map(
      (row) => `
        <div class="field">
          <span class="field-label">${escapeHtml(row.label)}</span>
          <span class="field-value">${escapeHtml(row.value)}</span>
        </div>
      `,
    )
    .join('');

  return `
    <div class="token-detail">
      <div class="token-detail-header">
        <span class="token-detail-title">${escapeHtml(token.text)}</span>
        <button class="icon-button" type="button" data-close-token-detail aria-label="Close token detail">x</button>
      </div>
      <div class="token-fields">${rows}</div>
    </div>
  `;
}

function renderTokenPreview(token: LearningToken, settings: ExtensionSettings): string {
  const preview = [
    settings.showRomanization ? token.romanization : null,
    settings.showGloss ? token.gloss ?? token.translation : null,
  ].filter((value): value is string => typeof value === 'string' && value.trim() !== '');

  return `
    <span class="token-inline-preview" role="tooltip">
      <strong>${escapeHtml(token.text)}</strong>
      ${preview.length > 0 ? `<span>${preview.map(escapeHtml).join(' | ')}</span>` : ''}
    </span>
  `;
}

function tokenDetailRows(token: LearningToken, settings: ExtensionSettings): { label: string; value: string }[] {
  const rows: { label: string; value: string }[] = [
    { label: 'Text', value: token.text },
    { label: 'Lemma', value: token.lemma ?? '' },
    { label: 'Root', value: token.root ?? '' },
    { label: 'Part of speech', value: token.partOfSpeech ?? '' },
  ];

  if (settings.showRomanization) {
    rows.push({ label: 'Romanization', value: token.romanization ?? '' });
  }

  if (settings.showGloss) {
    rows.push({ label: 'Gloss', value: token.gloss ?? token.translation ?? '' });
  }

  rows.push({ label: 'Usage note', value: token.usageNote ?? '' });

  return rows.filter((row) => row.value.trim() !== '');
}

function renderShell(input: { eyebrow: string; title: string; detail: string; meta: string[] }): string {
  const meta = input.meta.length
    ? `<div class="meta">${input.meta.map((item) => `<span>${escapeHtml(item)}</span>`).join('')}</div>`
    : '';

  return `
    <section class="shell" role="status">
      <div class="eyebrow">${escapeHtml(input.eyebrow)}</div>
      <div class="title">${escapeHtml(input.title)}</div>
      <div class="detail">${escapeHtml(input.detail)}</div>
      ${meta}
    </section>
  `;
}

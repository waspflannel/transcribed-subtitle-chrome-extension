import type { ExtensionSettings } from './settings-model';
import { escapeHtml } from './html';
import type { LearningToken, SubtitleCue } from './contracts';
import type { SubtitleState } from './messages';
import { hasLearningMetadata, tokenKey } from './track-tokens';
import type { YoutubePageInfo } from './youtube';

export interface OverlayRenderState {
  page: YoutubePageInfo;
  subtitleState: SubtitleState;
  settings: ExtensionSettings;
  activeCue?: SubtitleCue | null;
  videoPaused?: boolean;
  pendingTokenKeys?: ReadonlySet<string>;
  failedTokenKeys?: ReadonlySet<string>;
}

interface OverlayInteractionState {
  pinnedTokenIndex: number | null;
  copyStatus?: 'copied' | 'failed' | null;
  pendingTokenKeys?: ReadonlySet<string>;
  failedTokenKeys?: ReadonlySet<string>;
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
  private copyStatus: 'copied' | 'failed' | null = null;
  private copyStatusTimeout: number | null = null;

  public constructor(
    private readonly documentRef: Document = document,
    private readonly options: {
      onCopyCue?: (cue: SubtitleCue) => Promise<boolean>;
      onReplayCue?: (cue: SubtitleCue) => void;
      onStudyHoverEnd?: () => void;
      onTokenClick?: (cue: SubtitleCue, token: LearningToken) => void;
      onTokenPreview?: () => void;
      onTokenPreviewEnd?: () => void;
    } = {},
  ) {}

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
      this.copyStatus = null;
      this.clearCopyStatusTimeout();
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
    this.copyStatus = null;
    this.clearCopyStatusTimeout();
  }

  private render(): void {
    if (!this.content || !this.currentState) {
      return;
    }

    const html = renderOverlayContent(this.currentState, {
      pinnedTokenIndex: this.pinnedTokenIndex,
      copyStatus: this.copyStatus,
      pendingTokenKeys: this.currentState.pendingTokenKeys,
      failedTokenKeys: this.currentState.failedTokenKeys,
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

      button.addEventListener('pointerenter', () => {
        this.options.onTokenPreview?.();
      });

      button.addEventListener('focus', () => {
        this.options.onTokenPreview?.();
      });

      button.addEventListener('blur', () => {
        this.options.onTokenPreviewEnd?.();
      });

      button.addEventListener('click', () => {
        this.options.onTokenPreview?.();
        const cue = this.currentState?.activeCue;
        const token = cue?.tokens.find((candidate) => candidate.index === tokenIndex);
        const selectedTokenKey = cue && token ? tokenKey(cue.cueId, token.index) : null;
        const isFailedToken =
          selectedTokenKey !== null && (this.currentState?.failedTokenKeys?.has(selectedTokenKey) ?? false);
        const wasPinned = this.pinnedTokenIndex === tokenIndex;
        const shouldRetryFailedToken = wasPinned && isFailedToken;
        const shouldOpenToken = !wasPinned || shouldRetryFailedToken;

        this.pinnedTokenIndex = shouldOpenToken ? tokenIndex : null;

        if (shouldOpenToken && cue && token && !hasLearningMetadata(token)) {
          this.options.onTokenClick?.(cue, token);
        }

        this.render();
      });

      button.addEventListener('pointerleave', () => {
        this.options.onTokenPreviewEnd?.();
      });
    }

    this.content.querySelector<HTMLButtonElement>('[data-close-token-detail]')?.addEventListener('click', () => {
      this.pinnedTokenIndex = null;
      this.render();
    });

    const rail = this.content.querySelector<HTMLElement>('[data-study-rail]');

    rail?.addEventListener('pointerleave', () => {
      this.options.onStudyHoverEnd?.();
    });

    for (const button of this.content.querySelectorAll<HTMLButtonElement>('[data-study-control]')) {
      button.addEventListener('click', () => {
        void this.handleStudyControl(button.dataset.studyControl);
      });
    }
  }

  private async handleStudyControl(control: string | undefined): Promise<void> {
    const cue = this.currentState?.activeCue;

    if (!cue || !this.currentState) {
      return;
    }

    switch (control) {
      case 'replay':
        this.options.onReplayCue?.(cue);
        return;

      case 'copy':
        this.showCopyStatus((await this.options.onCopyCue?.(cue)) === true ? 'copied' : 'failed');
        return;

      default:
        return;
    }
  }

  private showCopyStatus(status: 'copied' | 'failed'): void {
    this.copyStatus = status;
    this.render();
    this.clearCopyStatusTimeout();

    const view = this.documentRef.defaultView;

    if (!view) {
      return;
    }

    this.copyStatusTimeout = view.setTimeout(() => {
      this.copyStatus = null;
      this.copyStatusTimeout = null;
      this.render();
    }, 1400);
  }

  private clearCopyStatusTimeout(): void {
    if (this.copyStatusTimeout === null) {
      return;
    }

    this.documentRef.defaultView?.clearTimeout(this.copyStatusTimeout);
    this.copyStatusTimeout = null;
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
          bottom: 82px;
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
          width: min(430px, calc(100vw - 32px));
        }

        .rail {
          background:
            linear-gradient(180deg, rgba(255, 253, 247, 0.08), rgba(255, 253, 247, 0.02)),
            rgba(8, 10, 14, 0.86);
          backdrop-filter: blur(20px) saturate(130%);
          border: 1px solid rgba(255, 253, 247, 0.14);
          border-radius: 10px;
          box-shadow: 0 18px 54px rgba(0, 0, 0, 0.42);
          color: #fffdf7;
          display: grid;
          font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
          gap: 12px;
          grid-template-columns: minmax(112px, auto) minmax(0, 1fr) auto;
          line-height: 1.35;
          margin: 0 auto;
          max-width: min(860px, calc(100vw - 32px));
          min-height: auto;
          padding: 12px 16px;
          pointer-events: auto;
        }

        .rail--message {
          max-width: min(720px, calc(100vw - 32px));
        }

        :host([data-position="compact"]) .rail {
          gap: 14px;
          grid-template-columns: minmax(0, 1fr);
          padding: 16px;
        }

        .rail-meta {
          align-content: start;
          display: flex;
          flex-wrap: wrap;
          gap: 8px;
          min-width: 0;
        }

        .eyebrow {
          color: #99f6e4;
          font-family: "Space Grotesk", Inter, ui-sans-serif, system-ui, sans-serif;
          font-size: 13px;
          font-weight: 850;
          letter-spacing: 0;
          white-space: nowrap;
        }

        .cue-time {
          color: #fbbf24;
          font-family: ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace;
          font-size: 12px;
          font-weight: 750;
          white-space: nowrap;
        }

        .rail-main {
          align-content: center;
          display: grid;
          gap: 8px;
          min-width: 0;
        }

        .rail-main--message {
          gap: 6px;
        }

        .rail-controls {
          align-content: start;
          align-items: center;
          display: flex;
          flex-wrap: wrap;
          gap: 6px;
          justify-content: flex-end;
          min-width: 0;
        }

        .study-control {
          background: rgba(255, 253, 247, 0.07);
          border: 1px solid rgba(255, 253, 247, 0.14);
          border-radius: 8px;
          color: #d1d5db;
          cursor: pointer;
          font: inherit;
          font-size: 11px;
          font-weight: 850;
          min-height: 28px;
          padding: 0 8px;
          transition:
            background 120ms ease,
            border-color 120ms ease,
            box-shadow 120ms ease,
            color 120ms ease,
            transform 120ms ease;
          white-space: nowrap;
        }

        .study-control:hover {
          background: rgba(255, 253, 247, 0.12);
          border-color: rgba(94, 234, 212, 0.32);
          transform: translateY(-1px);
        }

        .study-control:focus-visible {
          box-shadow: 0 0 0 3px rgba(94, 234, 212, 0.34);
          outline: none;
        }

        .study-control[aria-pressed="true"] {
          background: rgba(245, 158, 11, 0.18);
          border-color: rgba(245, 158, 11, 0.52);
          color: #fde68a;
        }

        .control-status {
          color: #99f6e4;
          font-size: 11px;
          font-weight: 850;
          line-height: 1;
          white-space: nowrap;
        }

        .control-status.failed {
          color: #fca5a5;
        }

        .title {
          color: #fffdf7;
          font-size: 15px;
          font-weight: 800;
        }

        .detail {
          color: #d1d5db;
          font-size: 13px;
        }

        .meta {
          color: #9ca3af;
          display: flex;
          flex-wrap: wrap;
          font-size: 12px;
          gap: 8px;
        }

        .token-area {
          align-items: center;
          display: flex;
          flex-wrap: wrap;
          gap: 6px;
          justify-content: center;
          min-width: 0;
        }

        .translation {
          color: #f8fffd;
          font-size: 17px;
          font-weight: 600;
          line-height: 1.25;
          overflow-wrap: anywhere;
        }

        .cue-romanization {
          color: #99f6e4;
          font-size: 13px;
          font-weight: 650;
        }

        .study-blur {
          filter: blur(6px);
          opacity: 0.78;
          transition:
            filter 120ms ease,
            opacity 120ms ease;
          user-select: none;
        }

        .token-card:hover .study-blur--token,
        .token-card:focus-visible .study-blur--token,
        .token-card[aria-pressed="true"] .study-blur--token,
        .study-cue-romanization:hover,
        .study-cue-romanization:focus-visible,
        .study-translation:hover,
        .study-translation:focus-visible {
          filter: blur(0);
          opacity: 1;
          user-select: text;
        }

        .token-slot {
          display: inline-grid;
          max-width: 100%;
          position: relative;
        }

        .token-card {
          align-items: center;
          background: rgba(255, 253, 247, 0.075);
          border: 1px solid rgba(255, 253, 247, 0.11);
          border-radius: 8px;
          color: inherit;
          cursor: pointer;
          display: inline-grid;
          font: inherit;
          gap: 3px;
          line-height: 1;
          min-height: 42px;
          min-width: 0;
          padding: 7px 12px;
          position: relative;
          text-align: center;
          transition:
            background 120ms ease,
            border-color 120ms ease,
            box-shadow 120ms ease,
            transform 120ms ease;
        }

        .token-card:hover {
          background: rgba(255, 253, 247, 0.11);
          border-color: rgba(251, 191, 36, 0.38);
          transform: translateY(-1px);
        }

        .token-card:focus-visible {
          box-shadow: 0 0 0 3px rgba(94, 234, 212, 0.34);
          outline: none;
        }

        .token-card[aria-pressed="true"] {
          background: rgba(245, 158, 11, 0.18);
          border-color: rgba(245, 158, 11, 0.78);
          box-shadow: inset 0 0 24px rgba(245, 158, 11, 0.08), 0 0 22px rgba(245, 158, 11, 0.12);
        }

        .token-text {
          color: #fffdf7;
          font-size: 22px;
          font-weight: 750;
          line-height: 1;
          overflow-wrap: anywhere;
        }

        .token-extra {
          color: #99f6e4;
          font-size: 11px;
          font-weight: 650;
          line-height: 1.3;
          overflow-wrap: anywhere;
        }

        .token-inline-preview,
        .token-popover {
          background: rgba(9, 10, 12, 0.96);
          border: 1px solid rgba(255, 253, 247, 0.14);
          border-radius: 10px;
          color: #e5e7eb;
          display: grid;
          font-size: 13px;
          gap: 8px;
          padding: 12px 14px;
        }

        .token-inline-preview {
          bottom: calc(100% + 8px);
          box-shadow: 0 12px 30px rgba(0, 0, 0, 0.3);
          display: none;
          left: 50%;
          min-width: 150px;
          position: absolute;
          transform: translateX(-50%);
          z-index: 1;
        }

        .token-card:hover .token-inline-preview,
        .token-card:focus-visible .token-inline-preview {
          display: grid;
        }

        .token-popover {
          bottom: calc(100% + 14px);
          box-shadow: 0 18px 50px rgba(0, 0, 0, 0.46);
          left: 50%;
          position: absolute;
          transform: translateX(-50%);
          width: min(270px, calc(100vw - 48px));
          z-index: 2;
        }

        .token-popover::after {
          background: rgba(9, 10, 12, 0.96);
          border-bottom: 1px solid rgba(255, 253, 247, 0.14);
          border-right: 1px solid rgba(255, 253, 247, 0.14);
          bottom: -6px;
          content: "";
          height: 10px;
          left: 50%;
          position: absolute;
          transform: translateX(-50%) rotate(45deg);
          width: 10px;
        }

        .token-popover-header {
          align-items: center;
          display: flex;
          gap: 10px;
          justify-content: space-between;
        }

        .token-popover-title {
          color: #fffdf7;
          font-size: 14px;
          font-weight: 850;
        }

        .icon-button {
          align-items: center;
          background: rgba(255, 253, 247, 0.08);
          border: 1px solid rgba(255, 253, 247, 0.16);
          border-radius: 7px;
          color: #e5e7eb;
          cursor: pointer;
          display: inline-flex;
          font-size: 14px;
          height: 28px;
          justify-content: center;
          line-height: 1;
          padding: 0;
          width: 28px;
        }

        .icon-button:hover,
        .icon-button:focus-visible {
          background: rgba(255, 253, 247, 0.14);
          box-shadow: 0 0 0 3px rgba(94, 234, 212, 0.22);
          outline: none;
        }

        .token-fields {
          display: grid;
          gap: 5px;
          grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        }

        .field {
          display: grid;
          gap: 1px;
        }

        .field-label {
          color: #9ca3af;
          font-size: 11px;
          font-weight: 800;
        }

        .field-value {
          color: #fffdf7;
          overflow-wrap: anywhere;
        }

        :host([data-position="compact"]) .rail-meta,
        :host([data-position="compact"]) .token-area,
        :host([data-position="compact"]) .rail-controls {
          justify-content: flex-start;
        }

        :host([data-position="compact"]) .token-card {
          min-height: 38px;
          padding: 6px 10px;
        }

        :host([data-position="compact"]) .token-text {
          font-size: 20px;
        }

        :host([data-position="compact"]) .translation {
          font-size: 16px;
        }

        :host([data-position="compact"]) .token-popover {
          bottom: auto;
          left: auto;
          margin-top: 8px;
          position: relative;
          transform: none;
          width: auto;
        }

        :host([data-position="compact"]) .token-popover::after {
          display: none;
        }

        @media (max-width: 899px) {
          :host {
            left: 16px;
            right: 16px;
          }

          .rail {
            grid-template-columns: minmax(0, 1fr);
            padding: 12px 14px;
          }

          .rail-controls {
            grid-column: 1 / -1;
            justify-content: flex-start;
          }

          .rail-main {
            grid-column: 1 / -1;
          }

          .translation {
            font-size: 16px;
          }

          .token-card {
            min-height: 40px;
          }

          .token-text {
            font-size: 21px;
          }
        }

        @media (max-width: 599px) {
          :host {
            bottom: 84px;
            left: 10px;
            right: 10px;
          }

          :host([data-position="top"]) {
            top: 64px;
          }

          .rail {
            gap: 8px;
            grid-template-columns: minmax(0, 1fr);
            padding: 10px 12px;
          }

          .rail-meta {
            gap: 6px;
          }

          .rail-controls {
            gap: 5px;
          }

          .study-control {
            font-size: 10px;
            min-height: 27px;
            padding: 0 7px;
          }

          .eyebrow,
          .cue-time {
            font-size: 12px;
          }

          .token-area {
            flex-wrap: nowrap;
            justify-content: flex-start;
            overflow-x: auto;
            padding-bottom: 2px;
          }

          .token-slot {
            flex: 0 0 auto;
          }

          .token-card {
            min-height: 38px;
            padding: 6px 10px;
          }

          .token-text {
            font-size: 19px;
          }

          .token-extra,
          .cue-romanization {
            font-size: 13px;
          }

          .translation {
            font-size: 15px;
          }

          .token-popover {
            bottom: auto;
            left: auto;
            margin-top: 8px;
            position: relative;
            transform: none;
            width: auto;
          }

          .token-popover::after {
            display: none;
          }
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
        ? `<div class="cue-romanization study-cue-romanization${studyBlurClass(
            state.settings.blurRomanization,
            'romanization',
          )}"${state.settings.blurRomanization ? ' tabindex="0"' : ''}>${escapeHtml(cue.romanization)}</div>`
        : '';

    return `
      <section class="rail" role="status" data-token-pinned="${
        interaction.pinnedTokenIndex === null ? 'false' : 'true'
      }" data-study-rail>
        <div class="rail-meta">
          <span class="eyebrow">AI subtitles</span>
          <span class="cue-time">${escapeHtml(formatCueTimeRange(cue))}</span>
        </div>
        <div class="rail-main">
          <div class="token-area" lang="${
            state.subtitleState.track.sourceLanguage === 'auto' ? 'und' : state.subtitleState.track.sourceLanguage
          }">${renderSourceLine(cue, state.settings, interaction)}</div>
          ${cueRomanization}
          ${renderTranslation(cue, state.settings)}
        </div>
        ${renderStudyControls(interaction.copyStatus)}
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
  return cue.tokens
    .map((token) => {
      const extras = [
        settings.showRomanization && token.romanization
          ? `<span class="token-extra study-token-romanization${studyBlurClass(
              settings.blurRomanization,
              'token',
            )}">${escapeHtml(token.romanization)}</span>`
          : '',
        settings.showGloss && (token.gloss ?? token.translation)
          ? `<span class="token-extra">${escapeHtml(token.gloss ?? token.translation ?? '')}</span>`
          : '',
      ].join('');
      const isPinned = interaction.pinnedTokenIndex === token.index;

      return `
        <span class="token-slot">
          <button class="token-card" type="button" data-token-index="${token.index}" aria-pressed="${
            isPinned ? 'true' : 'false'
          }" aria-label="Study word: ${escapeHtml(token.text)}">
            <span class="token-text${studyBlurClass(settings.blurSourceWords, 'token')}">${escapeHtml(
              token.text,
            )}</span>
            ${extras}
            ${renderTokenPreview(token, settings)}
          </button>
          ${isPinned ? renderTokenInteraction(cue, settings, interaction) : ''}
        </span>
      `;
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
    throw new TypeError(`Pinned token ${String(selectedIndex)} is not present on cue ${cue.cueId}.`);
  }

  const key = tokenKey(cue.cueId, token.index);
  const hasMetadata = hasLearningMetadata(token);
  const isPending = interactionTokenSet(interaction, 'pending')?.has(key) ?? false;
  const isFailed = interactionTokenSet(interaction, 'failed')?.has(key) ?? false;

  if (!hasMetadata || isPending || isFailed) {
    const detail = isFailed
      ? 'Word card generation failed. Select the word again to retry.'
      : 'Loading word card...';

    return `
      <div class="token-popover">
        <div class="token-popover-header">
          <span class="token-popover-title">${escapeHtml(token.text)}</span>
          <button class="icon-button" type="button" data-close-token-detail aria-label="Close token detail">x</button>
        </div>
        <div class="detail">${escapeHtml(detail)}</div>
      </div>
    `;
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
    <div class="token-popover">
      <div class="token-popover-header">
        <span class="token-popover-title">${escapeHtml(token.text)}</span>
        <button class="icon-button" type="button" data-close-token-detail aria-label="Close token detail">x</button>
      </div>
      <div class="token-fields">${rows}</div>
    </div>
  `;
}

function renderTranslation(cue: SubtitleCue, settings: ExtensionSettings): string {
  if (!settings.showTranslation || cue.translatedText.trim() === cue.sourceText.trim()) {
    return '';
  }

  return `<div class="translation study-translation${studyBlurClass(settings.blurTranslation, 'translation')}"${
    settings.blurTranslation ? ' tabindex="0"' : ''
  }>${escapeHtml(cue.translatedText)}</div>`;
}

function studyBlurClass(enabled: boolean, layer: 'token' | 'romanization' | 'translation'): string {
  return enabled ? ` study-blur study-blur--${layer}` : '';
}

function renderStudyControls(copyStatus: OverlayInteractionState['copyStatus']): string {
  const status = copyStatus
    ? `<span class="control-status ${copyStatus}" role="status">${
        copyStatus === 'copied' ? 'Copied' : 'Copy failed'
      }</span>`
    : '';

  return `
    <div class="rail-controls" aria-label="Subtitle study controls">
      <button class="study-control" type="button" data-study-control="replay">Replay</button>
      <button class="study-control" type="button" data-study-control="copy">Copy</button>
      ${status}
    </div>
  `;
}

function formatCueTimeRange(cue: SubtitleCue): string {
  return `${formatCueTimestamp(cue.startMs)} - ${formatCueTimestamp(cue.endMs)}`;
}

function formatCueTimestamp(milliseconds: number): string {
  const seconds = Math.max(0, Math.floor(milliseconds / 1000));
  const minutes = Math.floor(seconds / 60);
  const remainder = seconds % 60;

  return `${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
}

function interactionTokenSet(
  interaction: OverlayInteractionState,
  setName: 'pending' | 'failed',
): ReadonlySet<string> | undefined {
  return setName === 'pending' ? interaction.pendingTokenKeys : interaction.failedTokenKeys;
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
    <section class="rail rail--message" role="status">
      <div class="rail-meta">
        <span class="eyebrow">${escapeHtml(input.eyebrow)}</span>
      </div>
      <div class="rail-main rail-main--message">
        <div class="title">${escapeHtml(input.title)}</div>
        <div class="detail">${escapeHtml(input.detail)}</div>
        ${meta}
      </div>
    </section>
  `;
}

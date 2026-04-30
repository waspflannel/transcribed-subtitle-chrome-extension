import type { ExtensionSettings } from './settings-model';
import { escapeHtml } from './html';
import type { SubtitleState } from './messages';
import type { UnsupportedYoutubePageReason, YoutubePageInfo } from './youtube';

export interface OverlayRenderState {
  page: YoutubePageInfo;
  videoElementFound: boolean;
  subtitleState: SubtitleState;
  settings: ExtensionSettings;
}

export class OverlayShell {
  private host: HTMLDivElement | null = null;
  private content: HTMLDivElement | null = null;

  public constructor(private readonly documentRef: Document = document) {}

  public update(state: OverlayRenderState): void {
    if (!this.host || !this.content) {
      this.mount();
    }

    if (!this.host || !this.content) {
      return;
    }

    this.host.dataset.position = state.settings.overlayPosition;
    this.host.style.display = state.settings.overlayVisible ? 'block' : 'none';
    this.positionHost(state.settings.overlayPosition);
    this.content.innerHTML = renderOverlayContent(state);
  }

  public unmount(): void {
    this.host?.remove();
    this.host = null;
    this.content = null;
  }

  private mount(): void {
    const existingHost = this.documentRef.querySelector<HTMLDivElement>('#tse-overlay-host');

    if (existingHost?.shadowRoot) {
      this.host = existingHost;
      this.content = existingHost.shadowRoot.querySelector<HTMLDivElement>('[data-overlay-content]');

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
        }

        .shell {
          background: rgba(16, 24, 40, 0.92);
          border: 1px solid rgba(255, 255, 255, 0.16);
          border-radius: 8px;
          box-shadow: 0 12px 36px rgba(0, 0, 0, 0.28);
          color: #f8fafc;
          display: grid;
          font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
          gap: 6px;
          line-height: 1.35;
          margin: 0 auto;
          max-width: min(760px, calc(100vw - 32px));
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
          font-size: 18px;
          font-weight: 700;
        }

        .translation {
          color: #e2e8f0;
          font-size: 14px;
        }
      </style>
      <div data-overlay-content></div>
    `;

    this.host = host;
    this.content = shadowRoot.querySelector<HTMLDivElement>('[data-overlay-content]');
    (this.documentRef.body ?? this.documentRef.documentElement).append(host);
  }

  private positionHost(position: ExtensionSettings['overlayPosition']): void {
    if (!this.host) {
      return;
    }

    const style = this.host.style;
    style.bottom = '84px';
    style.left = '16px';
    style.pointerEvents = 'none';
    style.position = 'fixed';
    style.right = '16px';
    style.top = 'auto';
    style.width = 'auto';
    style.zIndex = '2147483647';

    if (position === 'top') {
      style.bottom = 'auto';
      style.top = '72px';
    }

    if (position === 'compact') {
      style.left = 'auto';
      style.right = '16px';
      style.width = 'min(360px, calc(100vw - 32px))';
    }
  }
}

function renderOverlayContent(state: OverlayRenderState): string {
  if (!state.page.supported) {
    return renderShell({
      eyebrow: 'AI subtitles',
      title: 'Unsupported page',
      detail: unsupportedPageCopy(state.page.reason),
      meta: [],
    });
  }

  if (!state.videoElementFound) {
    return renderShell({
      eyebrow: 'AI subtitles',
      title: 'Waiting for video',
      detail: 'The YouTube player is still loading.',
      meta: [`Video ${state.page.videoId}`],
    });
  }

  if (state.subtitleState.type === 'processing') {
    const progress = state.subtitleState.job.progress;

    return renderShell({
      eyebrow: 'AI subtitles',
      title: 'Generating subtitles',
      detail: progress?.message ?? 'Processing is in progress.',
      meta: [`Video ${state.page.videoId}`, progress ? `${progress.percent}%` : 'Queued'],
    });
  }

  if (state.subtitleState.type === 'ready') {
    const [cue] = state.subtitleState.track.cues;
    const optionalRows = [
      state.settings.showRomanization && cue.romanization
        ? `<div class="detail">${escapeHtml(cue.romanization)}</div>`
        : '',
      state.settings.showGloss ? renderTokenGloss(cue.tokens) : '',
    ].join('');

    return `
      <section class="shell" role="status">
        <div class="eyebrow">AI subtitles</div>
        <div class="line">${escapeHtml(cue.sourceText)}</div>
        <div class="translation">${escapeHtml(cue.translatedText)}</div>
        ${optionalRows}
        <div class="meta"><span>Video ${escapeHtml(state.page.videoId)}</span><span>Mock track</span></div>
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

  return renderShell({
    eyebrow: 'AI subtitles',
    title: 'No generated track',
    detail: 'This video does not have a generated subtitle track yet.',
    meta: [`Video ${state.page.videoId}`],
  });
}

function renderTokenGloss(tokens: { text: string; translation?: string; gloss?: string }[]): string {
  const gloss = tokens
    .map((token) => {
      const detail = token.gloss ?? token.translation;

      return detail ? `${token.text}: ${detail}` : token.text;
    })
    .join(' | ');

  return gloss ? `<div class="detail">${escapeHtml(gloss)}</div>` : '';
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

function unsupportedPageCopy(reason: UnsupportedYoutubePageReason): string {
  if (reason === 'missing_video_id' || reason === 'invalid_video_id') {
    return 'The current YouTube watch URL has no valid video ID.';
  }

  return 'YouTube watch page required.';
}

import { browser } from 'wxt/browser';
import type { LearningToken, SubtitleCue } from './contracts';
import { renderOverlayContent } from './overlay/overlay-render';
import { overlayStyles } from './overlay/overlay-styles';
import type { OverlayRenderState, OverlayStatus } from './overlay/types';
import { hasLearningMetadata, tokenKey } from './track-tokens';
import { findActiveYoutubeVideo } from './youtube-video';

const OVERLAY_FONTS: ReadonlyArray<readonly [string, number, string]> = [
  ['Geist Sans', 400, 'geist-sans-latin-400-normal.woff2'],
  ['Geist Sans', 500, 'geist-sans-latin-500-normal.woff2'],
  ['Geist Sans', 600, 'geist-sans-latin-600-normal.woff2'],
  ['Geist Sans', 700, 'geist-sans-latin-700-normal.woff2'],
  ['IBM Plex Mono', 400, 'ibm-plex-mono-latin-400-normal.woff2'],
  ['IBM Plex Mono', 500, 'ibm-plex-mono-latin-500-normal.woff2'],
];

function buildOverlayFontFaces(): string {
  const runtime = (globalThis as { browser?: typeof browser }).browser?.runtime ?? browser?.runtime;

  if (!runtime?.getURL) {
    return '';
  }

  return OVERLAY_FONTS.map(
    ([family, weight, file]) =>
      `@font-face{font-family:'${family}';font-style:normal;font-weight:${weight};font-display:swap;src:url('${runtime.getURL(
        ('fonts/' + file) as never,
      )}') format('woff2');}`,
  ).join('');
}

interface FocusSnapshot {
  key: string;
  selectionStart?: number | null;
  selectionEnd?: number | null;
}

export class OverlayShell {
  private host: HTMLDivElement | null = null;
  private content: HTMLDivElement | null = null;
  private renderedHtml: string | null = null;
  private currentState: OverlayRenderState | null = null;
  private currentCueId: string | null = null;
  private pinnedTokenIndex: number | null = null;
  private actionStatus: OverlayStatus | null = null;
  private actionStatusTimeout: number | null = null;
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

    const video = findActiveYoutubeVideo(this.documentRef);
    const parent = this.documentRef.fullscreenElement ?? this.documentRef.body ?? this.documentRef.documentElement;
    if (this.host!.parentElement !== parent) parent.append(this.host!);
    const rect = video?.getBoundingClientRect();
    const view = this.documentRef.defaultView;
    if (rect && view) {
      const left = Math.max(0, rect.left) + 16;
      const right = Math.max(0, view.innerWidth - rect.right) + 16;
      this.host!.style.left = state.settings.overlayPosition === 'compact' ? 'auto' : `${left}px`;
      this.host!.style.right = `${right}px`;
      this.host!.style.maxWidth = `${Math.max(0, view.innerWidth - left - right)}px`;
      this.host!.style.top = state.settings.overlayPosition === 'top' ? `${Math.max(0, rect.top) + 16}px` : 'auto';
      this.host!.style.bottom = state.settings.overlayPosition === 'top' ? 'auto'
        : `${Math.max(0, view.innerHeight - rect.bottom) + Math.min(82, rect.height / 4)}px`;
    }

    this.host!.dataset.position = state.settings.overlayPosition;
    this.host!.dataset.captionSize = state.settings.captionFontSize;
    this.host!.dataset.captionDensity = state.settings.captionDensity;
    this.host!.dataset.captionTheme = state.settings.captionContrastTheme;
    this.host!.style.display = state.settings.overlayVisible && video ? 'block' : 'none';
    this.currentState = state;

    const activeCueId = state.subtitleState.type === 'ready' ? state.activeCue?.cueId ?? null : null;

    if (activeCueId !== this.currentCueId) {
      this.currentCueId = activeCueId;
      this.pinnedTokenIndex = null;
      this.actionStatus = null;
      this.copyStatus = null;
      this.clearActionStatusTimeout();
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
    this.actionStatus = null;
    this.copyStatus = null;
    this.clearActionStatusTimeout();
    this.clearCopyStatusTimeout();
  }

  public showActionStatus(message: string, tone: OverlayStatus['tone'] = 'info'): void {
    this.actionStatus = { message, tone };
    this.render();

    this.clearActionStatusTimeout();

    const view = this.documentRef.defaultView;

    if (!view) {
      return;
    }

    this.actionStatusTimeout = view.setTimeout(() => {
      this.actionStatus = null;
      this.actionStatusTimeout = null;
      this.render();
    }, 1600);
  }

  private render(): void {
    if (!this.content || !this.currentState) {
      return;
    }

    const html = renderOverlayContent(this.currentState, {
      pinnedTokenIndex: this.pinnedTokenIndex,
      actionStatus: this.actionStatus,
      copyStatus: this.copyStatus,
      pendingTokenKeys: this.currentState.pendingTokenKeys,
      failedTokenKeys: this.currentState.failedTokenKeys,
    });

    if (html !== this.renderedHtml) {
      const focusSnapshot = this.focusSnapshotBeforeRender();

      this.content.innerHTML = html;
      this.renderedHtml = html;
      this.bindTokenInteractions();
      this.restoreFocusAfterRender(focusSnapshot);
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

  private clearActionStatusTimeout(): void {
    if (this.actionStatusTimeout === null) {
      return;
    }

    this.documentRef.defaultView?.clearTimeout(this.actionStatusTimeout);
    this.actionStatusTimeout = null;
  }

  private clearCopyStatusTimeout(): void {
    if (this.copyStatusTimeout === null) {
      return;
    }

    this.documentRef.defaultView?.clearTimeout(this.copyStatusTimeout);
    this.copyStatusTimeout = null;
  }

  private focusSnapshotBeforeRender(): FocusSnapshot | null {
    const activeElement = this.host?.shadowRoot?.activeElement;

    if (!(activeElement instanceof HTMLElement)) {
      return null;
    }

    if (activeElement instanceof HTMLInputElement && activeElement.dataset.focusKey) {
      return {
        key: activeElement.dataset.focusKey,
        selectionStart: activeElement.selectionStart,
        selectionEnd: activeElement.selectionEnd,
      };
    }

    return activeElement.dataset.focusKey ? { key: activeElement.dataset.focusKey } : null;
  }

  private restoreFocusAfterRender(focusSnapshot: FocusSnapshot | null): void {
    if (!this.content) {
      return;
    }

    if (!focusSnapshot) {
      return;
    }

    const focusTarget = this.content.querySelector<HTMLElement>(
      `[data-focus-key="${cssAttributeValue(focusSnapshot.key)}"]`,
    );

    focusTarget?.focus();

    if (focusTarget instanceof HTMLInputElement && typeof focusSnapshot.selectionStart === 'number') {
      focusTarget.setSelectionRange(focusSnapshot.selectionStart, focusSnapshot.selectionEnd ?? focusSnapshot.selectionStart);
    }
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
        ${buildOverlayFontFaces()}${overlayStyles}
      </style>
      <div data-overlay-content></div>
    `;

    this.host = host;
    this.content = shadowRoot.querySelector<HTMLDivElement>('[data-overlay-content]')!;
    (this.documentRef.body ?? this.documentRef.documentElement).append(host);
  }
}

function cssAttributeValue(value: string): string {
  return value.replaceAll('\\', '\\\\').replaceAll('"', '\\"');
}

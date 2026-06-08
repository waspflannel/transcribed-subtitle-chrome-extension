import { browser } from 'wxt/browser';
import type { LearningToken, SubtitleCue } from './contracts';
import { renderOverlayContent } from './overlay/overlay-render';
import { overlayStyles } from './overlay/overlay-styles';
import type { OverlayRenderState, OverlayStatus } from './overlay/types';
import { hasLearningMetadata, tokenKey } from './track-tokens';

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

export { renderOverlayContent } from './overlay/overlay-render';
export type { OverlayRenderState, OverlayStatus } from './overlay/types';

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
  private transcriptOpen = false;
  private transcriptSearchQuery = '';
  private transcriptStatus: OverlayStatus | null = null;
  private transcriptStatusTimeout: number | null = null;
  private transcriptReturnFocus: HTMLElement | null = null;
  private focusTranscriptSearchAfterRender = false;

  public constructor(
    private readonly documentRef: Document = document,
    private readonly options: {
      onCopyCue?: (cue: SubtitleCue) => Promise<boolean>;
      onJumpCue?: (cue: SubtitleCue) => void;
      onReplayCue?: (cue: SubtitleCue) => void;
      onSaveCue?: (cue: SubtitleCue) => void;
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
    this.host!.dataset.captionSize = state.settings.captionFontSize;
    this.host!.dataset.captionDensity = state.settings.captionDensity;
    this.host!.dataset.captionTheme = state.settings.captionContrastTheme;
    this.host!.style.display = state.settings.overlayVisible || this.transcriptOpen ? 'block' : 'none';
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
    this.transcriptOpen = false;
    this.transcriptSearchQuery = '';
    this.transcriptStatus = null;
    this.transcriptReturnFocus = null;
    this.focusTranscriptSearchAfterRender = false;
    this.clearActionStatusTimeout();
    this.clearCopyStatusTimeout();
    this.clearTranscriptStatusTimeout();
  }

  public isTranscriptOpen(): boolean {
    return this.transcriptOpen;
  }

  public toggleTranscript(): boolean {
    this.setTranscriptOpen(!this.transcriptOpen);

    return this.transcriptOpen;
  }

  public setTranscriptOpen(open: boolean): void {
    if (open === this.transcriptOpen) {
      return;
    }

    this.transcriptOpen = open;

    if (open) {
      const activeElement = this.documentRef.activeElement;

      this.transcriptReturnFocus = activeElement instanceof HTMLElement ? activeElement : null;
      this.focusTranscriptSearchAfterRender = true;
    } else {
      this.transcriptSearchQuery = '';
      this.transcriptStatus = null;
      this.clearTranscriptStatusTimeout();
    }

    this.render();

    if (!open) {
      this.restoreTranscriptReturnFocus();
    }
  }

  public showActionStatus(message: string, tone: OverlayStatus['tone'] = 'info'): void {
    this.actionStatus = { message, tone };

    if (this.transcriptOpen) {
      this.showTranscriptStatus(message, tone);
    } else {
      this.render();
    }

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
      transcriptOpen: this.transcriptOpen,
      transcriptSearchQuery: this.transcriptSearchQuery,
      transcriptStatus: this.transcriptStatus,
    });

    if (html !== this.renderedHtml) {
      const focusSnapshot = this.focusSnapshotBeforeRender();
      const transcriptScrollTop = this.transcriptScrollTopBeforeRender();

      this.content.innerHTML = html;
      this.renderedHtml = html;
      this.bindTokenInteractions();
      this.bindTranscriptInteractions();
      this.restoreFocusAfterRender(focusSnapshot);
      this.restoreTranscriptScrollAfterRender(transcriptScrollTop);
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

  private bindTranscriptInteractions(): void {
    if (!this.content) {
      return;
    }

    const searchInput = this.content.querySelector<HTMLInputElement>('[data-transcript-search]');

    searchInput?.addEventListener('input', () => {
      this.transcriptSearchQuery = searchInput.value;
      this.render();
    });

    const panel = this.content.querySelector<HTMLElement>('[data-transcript-panel]');

    panel?.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') {
        return;
      }

      event.preventDefault();
      this.setTranscriptOpen(false);
    });

    this.content.querySelector<HTMLButtonElement>('[data-transcript-close]')?.addEventListener('click', () => {
      this.setTranscriptOpen(false);
    });

    for (const button of this.content.querySelectorAll<HTMLButtonElement>('[data-transcript-action]')) {
      button.addEventListener('click', () => {
        void this.handleTranscriptAction(button.dataset.transcriptAction, button.dataset.cueId);
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

  private async handleTranscriptAction(action: string | undefined, cueId: string | undefined): Promise<void> {
    const cue = this.findCue(cueId);

    if (action === 'close') {
      this.setTranscriptOpen(false);

      return;
    }

    if (!cue) {
      this.showTranscriptStatus('No cue selected.', 'error');

      return;
    }

    switch (action) {
      case 'jump':
        this.options.onJumpCue?.(cue);
        this.showTranscriptStatus('Jumped to cue.', 'success');
        return;

      case 'replay':
        this.options.onReplayCue?.(cue);
        this.showTranscriptStatus('Replaying cue.', 'success');
        return;

      case 'copy':
        if ((await this.options.onCopyCue?.(cue)) === true) {
          this.showTranscriptStatus('Cue copied.', 'success');
        } else {
          this.showTranscriptStatus('Copy failed.', 'error');
        }

        return;

      case 'save':
        this.options.onSaveCue?.(cue);
        this.showTranscriptStatus('Save cue is reserved for Phase 02.', 'info');
        return;

      default:
        return;
    }
  }

  private findCue(cueId: string | undefined): SubtitleCue | null {
    if (!cueId || this.currentState?.subtitleState.type !== 'ready') {
      return null;
    }

    return this.currentState.subtitleState.track.cues.find((cue) => cue.cueId === cueId) ?? null;
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

  private showTranscriptStatus(message: string, tone: OverlayStatus['tone']): void {
    this.transcriptStatus = { message, tone };
    this.render();
    this.clearTranscriptStatusTimeout();

    const view = this.documentRef.defaultView;

    if (!view) {
      return;
    }

    this.transcriptStatusTimeout = view.setTimeout(() => {
      this.transcriptStatus = null;
      this.transcriptStatusTimeout = null;
      this.render();
    }, 1600);
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

  private clearTranscriptStatusTimeout(): void {
    if (this.transcriptStatusTimeout === null) {
      return;
    }

    this.documentRef.defaultView?.clearTimeout(this.transcriptStatusTimeout);
    this.transcriptStatusTimeout = null;
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

    if (this.focusTranscriptSearchAfterRender) {
      this.focusTranscriptSearchAfterRender = false;
      this.content.querySelector<HTMLInputElement>('[data-transcript-search]')?.focus();

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

  private restoreTranscriptReturnFocus(): void {
    this.transcriptReturnFocus?.focus();
    this.transcriptReturnFocus = null;
  }

  private transcriptScrollTopBeforeRender(): number | null {
    if (!this.transcriptOpen) {
      return null;
    }

    return this.content?.querySelector<HTMLElement>('[data-transcript-list]')?.scrollTop ?? null;
  }

  private restoreTranscriptScrollAfterRender(scrollTop: number | null): void {
    if (scrollTop === null) {
      return;
    }

    const transcriptList = this.content?.querySelector<HTMLElement>('[data-transcript-list]');

    if (transcriptList) {
      transcriptList.scrollTop = scrollTop;
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

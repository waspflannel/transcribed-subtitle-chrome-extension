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
  pendingTokenKeys?: ReadonlySet<string>;
  failedTokenKeys?: ReadonlySet<string>;
}

interface OverlayInteractionState {
  pinnedTokenIndex: number | null;
  actionStatus?: OverlayStatus | null;
  copyStatus?: 'copied' | 'failed' | null;
  pendingTokenKeys?: ReadonlySet<string>;
  failedTokenKeys?: ReadonlySet<string>;
  transcriptOpen?: boolean;
  transcriptSearchQuery?: string;
  transcriptStatus?: OverlayStatus | null;
}

const EMPTY_INTERACTION: OverlayInteractionState = {
  pinnedTokenIndex: null,
};

export interface OverlayStatus {
  message: string;
  tone: 'info' | 'success' | 'error';
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

        .control-status {
          color: #99f6e4;
          font-size: 11px;
          font-weight: 850;
          line-height: 1;
          white-space: nowrap;
        }

        .control-status.failed,
        .control-status.error {
          color: #fca5a5;
        }

        .control-status.info {
          color: #d1d5db;
        }

        .transcript-panel {
          background:
            linear-gradient(180deg, rgba(255, 253, 247, 0.08), rgba(255, 253, 247, 0.02)),
            rgba(8, 10, 14, 0.94);
          backdrop-filter: blur(20px) saturate(130%);
          border: 1px solid rgba(255, 253, 247, 0.16);
          border-radius: 10px;
          bottom: 92px;
          box-shadow: 0 18px 54px rgba(0, 0, 0, 0.46);
          color: #fffdf7;
          display: grid;
          font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
          gap: 12px;
          grid-template-rows: auto auto minmax(0, 1fr) auto;
          line-height: 1.35;
          max-width: calc(100vw - 32px);
          min-height: 280px;
          padding: 14px;
          pointer-events: auto;
          position: fixed;
          right: 16px;
          top: 72px;
          width: min(430px, calc(100vw - 32px));
          z-index: 2147483647;
        }

        .transcript-header {
          align-items: start;
          display: grid;
          gap: 10px;
          grid-template-columns: minmax(0, 1fr) auto;
        }

        .transcript-title {
          color: #fffdf7;
          font-size: 15px;
          font-weight: 900;
        }

        .transcript-summary {
          color: #d1d5db;
          font-size: 12px;
          margin-top: 2px;
        }

        .transcript-search {
          display: grid;
          gap: 5px;
        }

        .transcript-search label {
          color: #d1d5db;
          font-size: 12px;
          font-weight: 850;
        }

        .transcript-search input {
          background: rgba(0, 0, 0, 0.32);
          border: 1px solid rgba(255, 253, 247, 0.16);
          border-radius: 8px;
          color: #fffdf7;
          font: inherit;
          font-size: 13px;
          min-height: 34px;
          padding: 0 10px;
        }

        .transcript-search input:focus-visible,
        .transcript-action:focus-visible {
          box-shadow: 0 0 0 3px rgba(94, 234, 212, 0.34);
          outline: none;
        }

        .transcript-list {
          display: grid;
          gap: 8px;
          min-height: 0;
          overflow-y: auto;
          padding-right: 2px;
          scrollbar-color: rgba(94, 234, 212, 0.58) rgba(255, 253, 247, 0.08);
          scrollbar-width: thin;
        }

        .transcript-list::-webkit-scrollbar {
          width: 10px;
        }

        .transcript-list::-webkit-scrollbar-track {
          background: rgba(255, 253, 247, 0.08);
          border-radius: 999px;
        }

        .transcript-list::-webkit-scrollbar-thumb {
          background: rgba(94, 234, 212, 0.58);
          border: 2px solid rgba(8, 10, 14, 0.94);
          border-radius: 999px;
        }

        .transcript-list::-webkit-scrollbar-thumb:hover {
          background: rgba(251, 191, 36, 0.72);
        }

        .transcript-cue {
          background: rgba(255, 253, 247, 0.055);
          border: 1px solid rgba(255, 253, 247, 0.11);
          border-radius: 8px;
          display: grid;
          gap: 8px;
          padding: 10px;
        }

        .transcript-cue[aria-current="true"] {
          background: rgba(20, 184, 166, 0.16);
          border-color: rgba(94, 234, 212, 0.42);
          box-shadow: inset 3px 0 0 #5eead4;
        }

        .transcript-cue-header,
        .transcript-cue-actions {
          align-items: center;
          display: flex;
          flex-wrap: wrap;
          gap: 6px;
          justify-content: space-between;
        }

        .transcript-cue-index,
        .transcript-cue-time {
          color: #fbbf24;
          font-family: ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace;
          font-size: 11px;
          font-weight: 850;
        }

        .transcript-cue-body {
          display: grid;
          gap: 5px;
        }

        .transcript-source {
          color: #fffdf7;
          font-size: 14px;
          font-weight: 800;
          overflow-wrap: anywhere;
        }

        .transcript-romanization,
        .transcript-translation {
          color: #d1d5db;
          font-size: 12px;
          overflow-wrap: anywhere;
        }

        .transcript-romanization {
          color: #99f6e4;
        }

        .transcript-action {
          background: rgba(255, 253, 247, 0.07);
          border: 1px solid rgba(255, 253, 247, 0.14);
          border-radius: 8px;
          color: #e5e7eb;
          cursor: pointer;
          font: inherit;
          font-size: 11px;
          font-weight: 850;
          min-height: 28px;
          padding: 0 8px;
        }

        .transcript-action:hover {
          background: rgba(255, 253, 247, 0.12);
          border-color: rgba(94, 234, 212, 0.32);
        }

        .transcript-status {
          color: #d1d5db;
          font-size: 12px;
          font-weight: 850;
          min-height: 16px;
        }

        .transcript-status.success {
          color: #99f6e4;
        }

        .transcript-status.error {
          color: #fca5a5;
        }

        .transcript-empty {
          color: #d1d5db;
          font-size: 13px;
          margin: 0;
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

        :host([data-caption-size="small"]) .token-text {
          font-size: 19px;
        }

        :host([data-caption-size="small"]) .translation {
          font-size: 15px;
        }

        :host([data-caption-size="small"]) .cue-romanization,
        :host([data-caption-size="small"]) .token-extra {
          font-size: 11px;
        }

        :host([data-caption-size="large"]) .token-text {
          font-size: 26px;
        }

        :host([data-caption-size="large"]) .translation {
          font-size: 20px;
        }

        :host([data-caption-size="large"]) .cue-romanization,
        :host([data-caption-size="large"]) .token-extra {
          font-size: 14px;
        }

        :host([data-caption-density="compact"]) .rail {
          gap: 8px;
          padding: 9px 12px;
        }

        :host([data-caption-density="compact"]) .rail-main {
          gap: 5px;
        }

        :host([data-caption-density="compact"]) .token-card {
          min-height: 34px;
          padding: 5px 9px;
        }

        :host([data-caption-theme="high"]) .rail,
        :host([data-caption-theme="high"]) .transcript-panel,
        :host([data-caption-theme="high"]) .token-popover,
        :host([data-caption-theme="high"]) .token-inline-preview {
          background: #000;
          border-color: #fff;
          box-shadow: 0 0 0 2px #000, 0 18px 54px rgba(0, 0, 0, 0.6);
          color: #fff;
        }

        :host([data-caption-theme="high"]) .token-card,
        :host([data-caption-theme="high"]) .study-control,
        :host([data-caption-theme="high"]) .transcript-action,
        :host([data-caption-theme="high"]) .transcript-cue {
          background: #111;
          border-color: #fff;
          color: #fff;
        }

        :host([data-caption-theme="high"]) .token-text,
        :host([data-caption-theme="high"]) .translation,
        :host([data-caption-theme="high"]) .transcript-source,
        :host([data-caption-theme="high"]) .transcript-title {
          color: #fff;
        }

        :host([data-caption-theme="high"]) .cue-romanization,
        :host([data-caption-theme="high"]) .token-extra,
        :host([data-caption-theme="high"]) .transcript-romanization,
        :host([data-caption-theme="high"]) .eyebrow {
          color: #5eead4;
        }

        :host([data-caption-theme="high"]) .transcript-list {
          scrollbar-color: #5eead4 #111;
        }

        :host([data-caption-theme="high"]) .transcript-list::-webkit-scrollbar-track {
          background: #111;
          border: 1px solid #fff;
        }

        :host([data-caption-theme="high"]) .transcript-list::-webkit-scrollbar-thumb {
          background: #5eead4;
          border-color: #000;
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

          .transcript-panel {
            left: 16px;
            right: 16px;
            width: auto;
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

          .transcript-panel {
            bottom: 96px;
            left: 10px;
            min-height: 260px;
            padding: 12px;
            right: 10px;
            top: 64px;
            width: auto;
          }

          .transcript-cue-actions {
            justify-content: flex-start;
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
  const renderFrame = (railHtml: string): string => {
    const visibleRail = state.settings.overlayVisible ? railHtml : '';

    return `${visibleRail}${renderTranscriptPanel(state, interaction)}`;
  };

  if (!state.page.supported) {
    return renderFrame(renderShell({
      eyebrow: 'AI subtitles',
      title: 'Unsupported page',
      detail:
        state.page.reason === 'missing_video_id' || state.page.reason === 'invalid_video_id'
          ? 'The current YouTube URL has no valid video ID.'
          : 'YouTube video or Short required.',
      meta: [],
    }));
  }

  if (state.subtitleState.type === 'ready') {
    const cue = state.activeCue;

    if (!cue) {
      return renderFrame('');
    }

    const cueRomanization =
      state.settings.showRomanization && cue.romanization
        ? `<div class="cue-romanization study-cue-romanization${studyBlurClass(
            state.settings.blurRomanization,
            'romanization',
          )}"${
            state.settings.blurRomanization
              ? ' tabindex="0" aria-label="Cue romanization, focus to reveal blurred text"'
              : ''
          }>${escapeHtml(cue.romanization)}</div>`
        : '';

    return renderFrame(`
      <section class="rail" role="status" data-study-rail>
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
        ${renderStudyControls(interaction.copyStatus, interaction.actionStatus)}
      </section>
    `);
  }

  if (state.subtitleState.type === 'error') {
    return renderFrame(renderShell({
      eyebrow: 'AI subtitles',
      title: 'Subtitle generation failed',
      detail: state.subtitleState.message,
      meta: [`Video ${state.page.videoId}`],
    }));
  }

  if (state.subtitleState.type === 'loading') {
    return renderFrame(renderShell({
      eyebrow: 'AI subtitles',
      title: 'Generating subtitles',
      detail: state.subtitleState.message,
      meta: [`Video ${state.page.videoId}`],
    }));
  }

  return renderFrame(renderShell({
    eyebrow: 'AI subtitles',
    title: 'No generated track',
    detail: 'This video does not have a generated subtitle track yet.',
    meta: [`Video ${state.page.videoId}`],
  }));
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
    settings.blurTranslation ? ' tabindex="0" aria-label="Cue translation, focus to reveal blurred text"' : ''
  }>${escapeHtml(cue.translatedText)}</div>`;
}

function renderTranscriptPanel(state: OverlayRenderState, interaction: OverlayInteractionState): string {
  if (!interaction.transcriptOpen) {
    return '';
  }

  const query = interaction.transcriptSearchQuery ?? '';
  const readyState = state.subtitleState.type === 'ready' ? state.subtitleState : null;
  const cues = readyState ? filteredTranscriptCues(readyState.track.cues, query) : [];
  const totalCueCount = readyState?.track.cues.length ?? 0;
  const status = interaction.transcriptStatus ?? {
    message: readyState
      ? `${cues.length} of ${totalCueCount} cues`
      : 'Generate subtitles before using the transcript.',
    tone: 'info' as const,
  };
  const list = readyState
    ? renderTranscriptCueList(cues, state.activeCue?.cueId ?? null, state.settings)
    : '<p class="transcript-empty">No generated transcript is available for this video yet.</p>';

  return `
    <aside class="transcript-panel" data-transcript-panel role="complementary" aria-label="Generated transcript">
      <header class="transcript-header">
        <div>
          <div class="transcript-title">Transcript</div>
          <div class="transcript-summary">${escapeHtml(status.message)}</div>
        </div>
        <button
          class="transcript-action"
          type="button"
          data-transcript-close
          data-focus-key="transcript-close"
          aria-label="Close transcript"
        >Close</button>
      </header>
      <div class="transcript-search">
        <label for="tse-transcript-search">Search cues</label>
        <input
          id="tse-transcript-search"
          type="search"
          value="${escapeHtml(query)}"
          data-transcript-search
          data-focus-key="transcript-search"
          aria-describedby="tse-transcript-status"
          autocomplete="off"
        />
      </div>
      <div class="transcript-list" data-transcript-list role="list" aria-label="Generated cues">
        ${list}
      </div>
      <div
        id="tse-transcript-status"
        class="transcript-status ${escapeHtml(status.tone)}"
        role="status"
        aria-live="polite"
      >${escapeHtml(status.message)}</div>
    </aside>
  `;
}

function renderTranscriptCueList(
  cues: readonly SubtitleCue[],
  activeCueId: string | null,
  settings: ExtensionSettings,
): string {
  if (cues.length === 0) {
    return '<p class="transcript-empty">No cues match that search.</p>';
  }

  return cues.map((cue) => renderTranscriptCue(cue, cue.cueId === activeCueId, settings)).join('');
}

function renderTranscriptCue(cue: SubtitleCue, active: boolean, settings: ExtensionSettings): string {
  const romanization = settings.showRomanization && cue.romanization
    ? `<div class="transcript-romanization">${escapeHtml(cue.romanization)}</div>`
    : '';
  const translation =
    settings.showTranslation && cue.translatedText.trim() !== cue.sourceText.trim()
      ? `<div class="transcript-translation">${escapeHtml(cue.translatedText)}</div>`
      : '';

  return `
    <article class="transcript-cue" role="listitem" aria-current="${active ? 'true' : 'false'}">
      <div class="transcript-cue-header">
        <span class="transcript-cue-index">Cue ${cue.index + 1}</span>
        <span class="transcript-cue-time">${escapeHtml(formatCueTimeRange(cue))}</span>
      </div>
      <div class="transcript-cue-body">
        <div class="transcript-source">${escapeHtml(cue.sourceText)}</div>
        ${romanization}
        ${translation}
      </div>
      <div class="transcript-cue-actions" aria-label="Cue ${cue.index + 1} actions">
        ${transcriptActionButton('jump', cue, 'Jump')}
        ${transcriptActionButton('replay', cue, 'Replay')}
        ${transcriptActionButton('copy', cue, 'Copy')}
        ${transcriptActionButton('save', cue, 'Save')}
      </div>
    </article>
  `;
}

function transcriptActionButton(action: string, cue: SubtitleCue, label: string): string {
  return `
    <button
      class="transcript-action"
      type="button"
      data-transcript-action="${escapeHtml(action)}"
      data-cue-id="${escapeHtml(cue.cueId)}"
      data-focus-key="transcript-${escapeHtml(cue.cueId)}-${escapeHtml(action)}"
      aria-label="${escapeHtml(`${label} cue ${cue.index + 1}`)}"
    >${escapeHtml(label)}</button>
  `;
}

function filteredTranscriptCues(cues: readonly SubtitleCue[], query: string): readonly SubtitleCue[] {
  const normalizedQuery = query.trim().toLowerCase();

  if (normalizedQuery === '') {
    return cues;
  }

  return cues.filter((cue) => transcriptSearchText(cue).includes(normalizedQuery));
}

function transcriptSearchText(cue: SubtitleCue): string {
  return [
    cue.sourceText,
    cue.romanization ?? '',
    cue.translatedText,
    ...cue.tokens.flatMap((token) => [
      token.text,
      token.normalizedText,
      token.romanization ?? '',
      token.gloss ?? '',
      token.translation ?? '',
    ]),
  ]
    .join(' ')
    .toLowerCase();
}

function studyBlurClass(enabled: boolean, layer: 'token' | 'romanization' | 'translation'): string {
  return enabled ? ` study-blur study-blur--${layer}` : '';
}

function renderStudyControls(
  copyStatus: OverlayInteractionState['copyStatus'],
  actionStatus: OverlayInteractionState['actionStatus'],
): string {
  const visibleStatus = copyStatus
    ? {
        message: copyStatus === 'copied' ? 'Copied' : 'Copy failed',
        tone: copyStatus === 'copied' ? 'success' : 'error',
      }
    : actionStatus;
  const statusClass = copyStatus ?? visibleStatus?.tone;
  const status = visibleStatus
    ? `<span class="control-status ${statusClass}" role="status">${
        escapeHtml(visibleStatus.message)
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

function cssAttributeValue(value: string): string {
  return value.replaceAll('\\', '\\\\').replaceAll('"', '\\"');
}

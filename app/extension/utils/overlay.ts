import { escapeHtml } from './html';
import { t, setInterfaceLocale, interfaceLocale } from './i18n';
import { browser } from 'wxt/browser';
import type { LearningToken, SubtitleCue } from './contracts';
import { renderOverlayContent } from './overlay/overlay-render';
import { overlayStyles } from './overlay/overlay-styles';
import type { OverlayRenderState, OverlayStatus } from './overlay/types';
import { hasLearningMetadata, tokenKey } from './track-tokens';
import { findActiveYoutubeVideo } from './youtube-video';

// Fontsource subset ranges. Latin-ext covers romaji macrons (ō, ū) and pinyin tone marks (ǎ).
const LATIN_RANGE = 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD';
const LATIN_EXT_RANGE = 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF';

const OVERLAY_FONTS: ReadonlyArray<readonly [string, number, string, string?]> = [
  ['Geist Sans', 400, 'geist-sans-latin-400-normal.woff2'],
  ['Geist Sans', 600, 'geist-sans-latin-600-normal.woff2'],
  ['Geist Sans', 700, 'geist-sans-latin-700-normal.woff2'],
  ['IBM Plex Mono', 400, 'ibm-plex-mono-latin-400-normal.woff2', LATIN_RANGE],
  ['IBM Plex Mono', 400, 'ibm-plex-mono-latin-ext-400-normal.woff2', LATIN_EXT_RANGE],
  ['IBM Plex Mono', 500, 'ibm-plex-mono-latin-500-normal.woff2', LATIN_RANGE],
  ['IBM Plex Mono', 500, 'ibm-plex-mono-latin-ext-500-normal.woff2', LATIN_EXT_RANGE],
];

function buildOverlayFontFaces(): string {
  const runtime = (globalThis as { browser?: typeof browser }).browser?.runtime ?? browser?.runtime;

  if (!runtime?.getURL) {
    return '';
  }

  return OVERLAY_FONTS.map(
    ([family, weight, file, unicodeRange]) =>
      `@font-face{font-family:'${family}';font-style:normal;font-weight:${weight};font-display:swap;src:url('${runtime.getURL(
        ('fonts/' + file) as never,
      )}') format('woff2');${unicodeRange ? `unicode-range:${unicodeRange};` : ''}}`,
  ).join('');
}

interface FocusSnapshot {
  key: string;
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
  private focusKeyAfterRender: string | null = null;
  private dragHandle: HTMLButtonElement | null = null;
  private floatingPosition: { left: number; top: number; width: number } | null = null;
  private drag: { pointerId: number; offsetX: number; offsetY: number } | null = null;

  private readonly handleShadowKeydown = (event: KeyboardEvent): void => {
    if (event.key !== 'Escape' || this.pinnedTokenIndex === null || !this.currentState) {
      return;
    }

    const cueId = this.currentState.activeCue?.cueId ?? this.currentCueId;
    if (!cueId) return;
    this.focusKeyAfterRender = `${cueId}:${this.pinnedTokenIndex}`;
    this.pinnedTokenIndex = null;
    event.preventDefault();
    this.render();
  };

  public constructor(
    private readonly documentRef: Document = document,
    private readonly options: {
      onCopyCue?: (cue: SubtitleCue) => Promise<boolean>;
      onReplayCue?: (cue: SubtitleCue) => void;
      onRetryBinding?: () => void;
      onStudyHoverEnd?: () => void;
      onTokenFocus?: () => void;
      onTokenBlur?: () => void;
      onTokenClick?: (cue: SubtitleCue, token: LearningToken) => void;
      onTokenPreview?: () => void;
      onTokenPreviewEnd?: () => void;
    } = {},
  ) {}

  public update(state: OverlayRenderState, video = findActiveYoutubeVideo(this.documentRef)): void {
    setInterfaceLocale(state.settings.interfaceLocale);
    if (!this.host || !this.content) {
      this.mount();
    }

    this.host!.dataset.position = state.settings.overlayPosition;
    this.host!.lang = interfaceLocale();
    if (this.dragHandle) {
      this.dragHandle.textContent = t('Move subtitles');
      this.dragHandle.setAttribute('aria-label', t('Move subtitles. Drag or use arrow keys; hold Shift for larger steps.'));
      this.dragHandle.title = t('Drag to move subtitles. Arrow keys move; Shift moves faster.');
    }
    this.host!.dataset.captionSize = state.settings.captionFontSize;
    this.host!.dataset.captionDensity = state.settings.captionDensity;
    this.host!.dataset.captionTheme = state.settings.captionContrastTheme;
    if (state.settings.overlayAttachedToVideo) {
      this.endDrag();
      this.floatingPosition = null;
      delete this.host!.dataset.floating;
      this.host!.style.width = '';
    }
    this.currentState = state;
    this.position(video);

    const activeCueId = state.subtitleState.type === 'ready' ? state.activeCue?.cueId ?? null : null;

    if (activeCueId !== this.currentCueId) {
      this.options.onTokenPreviewEnd?.();
      this.options.onTokenBlur?.();
      this.currentCueId = activeCueId;
      this.pinnedTokenIndex = null;
      this.focusKeyAfterRender = null;
      this.actionStatus = null;
      this.copyStatus = null;
      this.clearActionStatusTimeout();
      this.clearCopyStatusTimeout();
    }

    this.render();
  }

  public position(video = findActiveYoutubeVideo(this.documentRef), videoRect?: DOMRect): void {
    if (!this.host || !this.currentState) return;
    this.host.style.display = this.currentState.settings.overlayVisible && video ? 'block' : 'none';
    if (!this.currentState.settings.overlayVisible || !video) {
      this.endDrag();
      return;
    }
    const parent = this.documentRef.fullscreenElement ?? this.documentRef.body ?? this.documentRef.documentElement;
    if (this.host.parentElement !== parent) {
      this.endDrag();
      parent.append(this.host);
    }
    if (this.floatingPosition) {
      this.positionFloating();
      this.constrainPopovers();
      return;
    }
    const rect = videoRect ?? video?.getBoundingClientRect();
    const view = this.documentRef.defaultView;
    if (rect && view) {
      const left = Math.max(0, rect.left) + 16;
      const right = Math.max(0, view.innerWidth - rect.right) + 16;
      this.host!.style.left = this.currentState.settings.overlayPosition === 'compact' ? 'auto' : `${left}px`;
      this.host!.style.right = `${right}px`;
      this.host!.style.maxWidth = `${Math.max(0, view.innerWidth - left - right)}px`;
      this.host!.style.top = this.currentState.settings.overlayPosition === 'top' ? `${Math.max(0, rect.top) + 16}px` : 'auto';
      this.host!.style.bottom = this.currentState.settings.overlayPosition === 'top' ? 'auto'
        : `${Math.max(0, view.innerHeight - rect.bottom) + Math.min(82, rect.height / 4)}px`;
    }

    if (!this.currentState.settings.overlayAttachedToVideo) this.positionFloating();
    this.constrainPopovers();
  }

  public unmount(): void {
    this.endDrag();
    this.options.onTokenPreviewEnd?.();
    this.options.onTokenBlur?.();
    this.host?.remove();
    this.host = null;
    this.content = null;
    this.dragHandle = null;
    this.floatingPosition = null;
    this.renderedHtml = null;
    this.currentState = null;
    this.currentCueId = null;
    this.pinnedTokenIndex = null;
    this.focusKeyAfterRender = null;
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

    this.dragHandle!.hidden = this.currentState.settings.overlayAttachedToVideo || html === '';
    if (this.dragHandle!.hidden) this.endDrag();
    if (!this.currentState.settings.overlayAttachedToVideo) this.positionFloating();
    this.constrainPopovers();
  }

  private positionFloating(): void {
    const view = this.documentRef.defaultView;
    if (!this.host || !view || this.host.style.display === 'none') return;
    if (!this.floatingPosition) {
      const rect = this.content?.querySelector('.rail')?.getBoundingClientRect();
      if (!rect || rect.width <= 0) return;
      this.floatingPosition = { left: rect.left, top: rect.top, width: rect.width };
    }
    const position = this.floatingPosition;

    this.host.dataset.floating = 'true';
    this.host.style.width = `${Math.min(position.width, Math.max(0, view.innerWidth - 16))}px`;
    this.host.style.maxWidth = 'none';
    this.host.style.right = 'auto';
    this.host.style.bottom = 'auto';
    const rect = this.host.getBoundingClientRect();
    position.left = Math.max(8, Math.min(position.left, view.innerWidth - rect.width - 8));
    // Leave room above the rail for the drag handle, even with a tall cue.
    position.top = Math.max(36, Math.min(position.top, view.innerHeight - rect.height - 8));
    this.host.style.left = `${position.left}px`;
    this.host.style.top = `${position.top}px`;
  }

  private endDrag(): void {
    const pointerId = this.drag?.pointerId;
    this.drag = null;
    this.dragHandle?.removeAttribute('data-dragging');
    if (pointerId !== undefined && this.dragHandle?.hasPointerCapture(pointerId)) {
      this.dragHandle.releasePointerCapture(pointerId);
    }
  }

  private bindMovement(): void {
    const handle = this.dragHandle!;
    handle.addEventListener('pointerdown', (event) => {
      if (event.button !== 0 || !event.isPrimary || !this.floatingPosition || handle.hidden) return;
      event.preventDefault();
      event.stopPropagation();
      this.drag = {
        pointerId: event.pointerId,
        offsetX: event.clientX - this.floatingPosition.left,
        offsetY: event.clientY - this.floatingPosition.top,
      };
      handle.setPointerCapture(event.pointerId);
      handle.dataset.dragging = 'true';
    });
    handle.addEventListener('pointermove', (event) => {
      if (!this.drag || event.pointerId !== this.drag.pointerId || !this.floatingPosition) return;
      event.preventDefault();
      event.stopPropagation();
      this.floatingPosition.left = event.clientX - this.drag.offsetX;
      this.floatingPosition.top = event.clientY - this.drag.offsetY;
      this.positionFloating();
      this.constrainPopovers();
    });
    for (const type of ['pointerup', 'pointercancel', 'lostpointercapture']) {
      handle.addEventListener(type, (event) => {
        if ((event as PointerEvent).pointerId === this.drag?.pointerId) this.endDrag();
      });
    }
    handle.addEventListener('keydown', (event) => {
      if (!this.floatingPosition || handle.hidden) return;
      const step = event.shiftKey ? 50 : 10;
      switch (event.key) {
        case 'ArrowLeft': this.floatingPosition.left -= step; break;
        case 'ArrowRight': this.floatingPosition.left += step; break;
        case 'ArrowUp': this.floatingPosition.top -= step; break;
        case 'ArrowDown': this.floatingPosition.top += step; break;
        default: return;
      }
      event.preventDefault();
      event.stopPropagation();
      this.positionFloating();
      this.constrainPopovers();
    });
  }

  private bindTokenInteractions(): void {
    if (!this.content) {
      return;
    }

    const handleFocusBlur = (event: FocusEvent): void => {
      const shadowActiveElement = this.host?.shadowRoot?.activeElement;
      const focusRemainsWithinStudyControls = (target: EventTarget | null): boolean => {
        if (!(target instanceof Element)) return false;
        const control = target.closest('[data-token-index], [data-close-token-detail]');
        return control !== null && this.content?.contains(control) === true;
      };

      if (
        focusRemainsWithinStudyControls(event.relatedTarget)
        || (event.relatedTarget === null && focusRemainsWithinStudyControls(shadowActiveElement ?? null))
        || (event.relatedTarget === null && focusRemainsWithinStudyControls(this.content?.querySelector(':focus') ?? null))
      ) return;
      this.options.onTokenBlur?.();
    };

    for (const button of this.content.querySelectorAll<HTMLButtonElement>('[data-token-index]')) {
      const tokenIndex = Number(button.dataset.tokenIndex);

      if (!Number.isInteger(tokenIndex)) {
        continue;
      }

      button.addEventListener('pointerenter', () => {
        this.options.onTokenPreview?.();
      });

      button.addEventListener('focus', () => {
        this.options.onTokenFocus?.();
      });

      button.addEventListener('blur', handleFocusBlur);

      button.addEventListener('click', () => {
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

    const closeButton = this.content.querySelector<HTMLButtonElement>('[data-close-token-detail]');
    closeButton?.addEventListener('blur', handleFocusBlur);
    closeButton?.addEventListener('click', () => {
      this.focusKeyAfterRender = this.content?.querySelector<HTMLButtonElement>('[data-close-token-detail]')
        ?.dataset.returnFocusKey ?? null;
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

    this.content.querySelector<HTMLButtonElement>('[data-retry-binding]')?.addEventListener('click', () => {
      this.options.onRetryBinding?.();
    });
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

    return activeElement.dataset.focusKey ? { key: activeElement.dataset.focusKey } : null;
  }

  private restoreFocusAfterRender(focusSnapshot: FocusSnapshot | null): void {
    if (!this.content) {
      return;
    }

    if (!focusSnapshot) {
      if (this.focusKeyAfterRender === null) return;
    }

    const focusKey = this.focusKeyAfterRender ?? focusSnapshot?.key;
    this.focusKeyAfterRender = null;
    if (!focusKey) return;
    const focusTarget = this.content.querySelector<HTMLElement>(
      `[data-focus-key="${cssAttributeValue(focusKey)}"]`,
    );

    focusTarget?.focus();
  }

  private constrainPopovers(): void {
    const view = this.documentRef.defaultView;
    if (!view || !this.content) return;

    for (const popover of this.content.querySelectorAll<HTMLElement>('.token-popover')) {
      const padding = 8;
      const maxHeight = Math.max(0, Math.min(view.innerHeight * 0.6, view.innerHeight - padding * 2));
      popover.style.maxHeight = `${maxHeight}px`;
      popover.style.setProperty('--popover-shift', '0px');
      popover.style.setProperty('--popover-shift-y', '0px');
      const rect = popover.getBoundingClientRect();
      const shift = rect.left < padding
        ? padding - rect.left
        : rect.right > view.innerWidth - padding
          ? view.innerWidth - padding - rect.right
          : 0;
      const shiftY = rect.top < padding
        ? padding - rect.top
        : rect.bottom > view.innerHeight - padding
          ? view.innerHeight - padding - rect.bottom
          : 0;
      if (shift !== 0) popover.style.setProperty('--popover-shift', `${shift}px`);
      if (shiftY !== 0) popover.style.setProperty('--popover-shift-y', `${shiftY}px`);
    }
  }

  private mount(): void {
    const existingHost = this.documentRef.querySelector<HTMLDivElement>('#tse-overlay-host');

    if (existingHost?.shadowRoot) {
      this.host = existingHost;
      this.content = existingHost.shadowRoot.querySelector<HTMLDivElement>('[data-overlay-content]')!;
      this.dragHandle = existingHost.shadowRoot.querySelector<HTMLButtonElement>('[data-overlay-drag]')!;
      this.renderedHtml = null;
      this.bindMovement();

      return;
    }

    const host = this.documentRef.createElement('div');
    host.id = 'tse-overlay-host';
    host.setAttribute('aria-live', 'polite');

    const shadowRoot = host.attachShadow({ mode: 'open' });
    shadowRoot.addEventListener('keydown', (event) => this.handleShadowKeydown(event as KeyboardEvent));
    shadowRoot.innerHTML = `
      <style>
        ${buildOverlayFontFaces()}${overlayStyles}
      </style>
      <button type="button" class="drag-handle" data-overlay-drag hidden
        aria-label="${escapeHtml(t("Move subtitles. Drag or use arrow keys; hold Shift for larger steps."))}"
        title="${escapeHtml(t("Drag to move subtitles. Arrow keys move; Shift moves faster."))}">${escapeHtml(t("Move subtitles"))}</button>
      <div data-overlay-content></div>
    `;

    this.host = host;
    this.content = shadowRoot.querySelector<HTMLDivElement>('[data-overlay-content]')!;
    this.dragHandle = shadowRoot.querySelector<HTMLButtonElement>('[data-overlay-drag]')!;
    this.bindMovement();
    (this.documentRef.body ?? this.documentRef.documentElement).append(host);
  }
}

function cssAttributeValue(value: string): string {
  return value.replaceAll('\\', '\\\\').replaceAll('"', '\\"');
}

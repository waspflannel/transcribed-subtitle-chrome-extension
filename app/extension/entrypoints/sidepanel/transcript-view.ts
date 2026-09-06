import { browser } from 'wxt/browser';

import type { SubtitleCue } from '../../utils/contracts';
import type { ExtensionSettings } from '../../utils/settings-model';
import { panelTranscriptListHtml } from '../../utils/panel/transcript';
import { lyricsCharacterCount, QUICK_FIX_CHARACTER_LIMIT } from '../../utils/lyrics-correction';

export interface QuickFixSelection {
  cueId: string;
  tokenIndex: number;
  text: string;
}

export function bindTranscriptView(dom: {
  transcriptSearch: HTMLInputElement;
  transcriptList: HTMLElement;
  transcriptStatus: HTMLElement;
  onQuickFixSelect?: (cueId: string, tokenIndex: number) => void;
  onQuickFixSave?: (cueId: string, tokenIndex: number, value: string) => void;
  onQuickFixCancel?: () => void;
}) {
  let cues: readonly SubtitleCue[] = [];
  let settings: ExtensionSettings | null = null;
  let youtubeVideoId: string | null = null;
  let activeCueId: string | null = null;
  let renderedSignature: string | null = null;
  let quickFixMode = false;
  let quickFixEditing: QuickFixSelection | null = null;
  let quickFixDraft = '';
  let quickFixBusy = false;
  let quickFixError: string | null = null;
  let focusQuickFixEditor = false;

  function editingKey(): string | null {
    return quickFixEditing ? `${quickFixEditing.cueId}:${quickFixEditing.tokenIndex}` : null;
  }

  function renderSignature(): string {
    return JSON.stringify([
      youtubeVideoId,
      cues.map((cue) => cue.cueId),
      dom.transcriptSearch.value,
      settings?.showRomanization ?? false,
      settings?.showTranslation ?? false,
      quickFixMode,
      editingKey(),
    ]);
  }

  function render(): void {
    if (!settings) return;
    const total = cues.length;
    const signature = renderSignature();
    dom.transcriptStatus.textContent = total === 0 ? '' : `${total} cues`;
    if (signature === renderedSignature) return;
    renderedSignature = signature;
    dom.transcriptList.innerHTML = total === 0
      ? '<p class="transcript-empty muted">Generate subtitles to see the transcript.</p>'
      : panelTranscriptListHtml({
        cues,
        activeCueId,
        query: dom.transcriptSearch.value,
        settings,
        quickFixMode,
        quickFixEditing: quickFixEditing
          ? { cueId: quickFixEditing.cueId, tokenIndex: quickFixEditing.tokenIndex, value: quickFixDraft }
          : null,
      });
    hydrateQuickFixEditor();
  }

  /** Restore the inline editor's live state after a rebuild; typing never rebuilds. */
  function hydrateQuickFixEditor(): void {
    const input = quickFixInputElement();
    if (!input) return;
    if (input.value !== quickFixDraft) input.value = quickFixDraft;
    updateQuickFixEditor();
    if (focusQuickFixEditor) {
      focusQuickFixEditor = false;
      input.focus();
      input.select();
      input.scrollIntoView({ block: 'nearest' });
    }
  }

  function quickFixInputElement(): HTMLInputElement | null {
    return dom.transcriptList.querySelector<HTMLInputElement>('[data-quick-fix-input]');
  }

  function updateQuickFixEditor(): void {
    const editor = dom.transcriptList.querySelector('[data-quick-fix-editor]');
    if (!editor || !quickFixEditing) return;
    const input = editor.querySelector<HTMLInputElement>('[data-quick-fix-input]');
    const hint = editor.querySelector<HTMLElement>('[data-quick-fix-hint]');
    const save = editor.querySelector<HTMLButtonElement>('[data-transcript-action="quick-fix-save"]');
    const cancel = editor.querySelector<HTMLButtonElement>('[data-transcript-action="quick-fix-cancel"]');
    if (!input || !hint || !save || !cancel) return;

    const count = lyricsCharacterCount(quickFixDraft);
    const overLimit = count > QUICK_FIX_CHARACTER_LIMIT;
    const unchanged = quickFixDraft.trim() === quickFixEditing.text.trim();

    input.disabled = quickFixBusy;
    cancel.disabled = quickFixBusy;
    save.disabled = quickFixBusy || overLimit || unchanged || quickFixDraft.trim() === '';

    if (quickFixError) {
      hint.textContent = quickFixError;
      hint.classList.add('error');
    } else {
      hint.textContent = `${count} / ${QUICK_FIX_CHARACTER_LIMIT}`;
      hint.classList.toggle('error', overLimit);
    }
  }

  function trySaveQuickFix(): void {
    if (!quickFixEditing || quickFixBusy) return;
    const value = quickFixDraft;
    const count = lyricsCharacterCount(value);
    if (value.trim() === '' || value.trim() === quickFixEditing.text.trim() || count > QUICK_FIX_CHARACTER_LIMIT) return;
    dom.onQuickFixSave?.(quickFixEditing.cueId, quickFixEditing.tokenIndex, value);
  }

  function applyActiveCue(nextCueId: string | null): void {
    for (const row of dom.transcriptList.querySelectorAll('.cue.on')) {
      row.classList.remove('on');
      row.setAttribute('aria-current', 'false');
    }
    if (nextCueId === null) return;
    const nextRow = dom.transcriptList.querySelector(`[data-cue-id="${cssAttributeValue(nextCueId)}"]`);
    if (!nextRow) return;
    nextRow.classList.add('on');
    nextRow.setAttribute('aria-current', 'true');
    nextRow.scrollIntoView({ block: 'nearest' });
  }

  function ackButton(button: HTMLButtonElement, label?: string): void {
    button.classList.add('acted');
    window.setTimeout(() => button.classList.remove('acted'), 300);
    if (label) {
      if (button.dataset.label === undefined) button.dataset.label = button.textContent ?? '';
      button.textContent = label;
      window.setTimeout(() => { button.textContent = button.dataset.label ?? ''; }, 1000);
    }
  }

  dom.transcriptSearch.addEventListener('input', render);
  dom.transcriptList.addEventListener('click', (event) => {
    const button = (event.target as Element)?.closest<HTMLButtonElement>('[data-transcript-action]');
    const cueId = button?.dataset.cueId;
    if (!button) return;
    const action = button.dataset.transcriptAction;
    if (action === 'quick-fix-token') {
      const tokenIndex = Number(button.dataset.tokenIndex);
      if (cueId && Number.isInteger(tokenIndex) && tokenIndex >= 0) {
        dom.onQuickFixSelect?.(cueId, tokenIndex);
      }
      return;
    }
    if (action === 'quick-fix-save') {
      trySaveQuickFix();
      return;
    }
    if (action === 'quick-fix-cancel') {
      dom.onQuickFixCancel?.();
      return;
    }
    if (!cueId) return;
    if (action === 'jump') {
      activeCueId = cueId;
      applyActiveCue(cueId);
      if (youtubeVideoId) {
        void browser.runtime.sendMessage({ type: 'panel.seekToCue', youtubeVideoId, cueId, mode: 'jump' }).catch(() => {});
      }
      ackButton(button);
    } else if (action === 'copy') {
      const text = cues.find((c) => c.cueId === cueId)?.sourceText;
      if (text && navigator.clipboard) {
        void navigator.clipboard.writeText(text)
          .then(() => ackButton(button, 'Copied'))
          .catch(() => ackButton(button, 'Failed'));
      } else {
        ackButton(button, 'Failed');
      }
    }
  });

  dom.transcriptList.addEventListener('input', (event) => {
    const input = (event.target as Element)?.closest<HTMLInputElement>('[data-quick-fix-input]');
    if (!input) return;
    quickFixDraft = input.value;
    quickFixError = null;
    updateQuickFixEditor();
  });

  dom.transcriptList.addEventListener('keydown', (event) => {
    const input = (event.target as Element)?.closest<HTMLInputElement>('[data-quick-fix-input]');
    if (!input) return;
    if (event.key === 'Enter') {
      event.preventDefault();
      trySaveQuickFix();
    } else if (event.key === 'Escape') {
      event.preventDefault();
      dom.onQuickFixCancel?.();
    }
  });

  return {
    setData(nextYoutubeVideoId: string | null, nextCues: readonly SubtitleCue[], nextSettings: ExtensionSettings) {
      youtubeVideoId = nextYoutubeVideoId; cues = nextCues; settings = nextSettings; render();
    },
    setActiveCue(cueId: string | null) {
      if (cueId === activeCueId) return;
      activeCueId = cueId;
      applyActiveCue(cueId);
    },
    setQuickFixMode(enabled: boolean) {
      if (quickFixMode === enabled) return;
      quickFixMode = enabled;
      render();
    },
    /** Open the inline editor on a token, or close it with null. Focuses the input on open. */
    setQuickFixEditing(selection: QuickFixSelection | null) {
      const nextKey = selection ? `${selection.cueId}:${selection.tokenIndex}` : null;
      if (nextKey === editingKey()) return;
      quickFixEditing = selection;
      quickFixDraft = selection?.text ?? '';
      quickFixError = null;
      focusQuickFixEditor = selection !== null;
      render();
    },
    setQuickFixBusy(busy: boolean) {
      quickFixBusy = busy;
      if (busy) quickFixError = null;
      updateQuickFixEditor();
    },
    setQuickFixError(message: string | null) {
      quickFixError = message;
      updateQuickFixEditor();
    },
    focus() { dom.transcriptSearch.focus(); },
  };
}

function cssAttributeValue(value: string): string {
  return value.replaceAll('\\', '\\\\').replaceAll('"', '\\"');
}

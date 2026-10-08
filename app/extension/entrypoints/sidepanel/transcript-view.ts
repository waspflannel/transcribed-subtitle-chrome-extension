import { escapeHtml } from '../../utils/html';
import { t } from '../../utils/i18n';
import type { PartialSubtitleCue, SubtitleCue } from '../../utils/contracts';
import type { ExtensionSettings } from '../../utils/settings-model';
import { panelPartialTranscriptListHtml, panelTranscriptListHtml, transcriptSearchText, type TranscriptLanguages } from '../../utils/panel/transcript';
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
  onSeekToCue?: (cueId: string, mode: 'jump' | 'replay') => void;
  onQuickFixSelect?: (cueId: string, tokenIndex: number) => void;
  onQuickFixSave?: (cueId: string, tokenIndex: number, value: string) => void;
  onQuickFixCancel?: () => void;
}) {
  let cues: readonly SubtitleCue[] = [];
  let cueContentSignature = '';
  let cueRevision = 0;
  let searchableText: readonly string[] = [];
  let searchRenderPending = false;
  let partialCues: readonly PartialSubtitleCue[] = [];
  let partial = false;
  let settings: ExtensionSettings | null = null;
  let languages: TranscriptLanguages | undefined;
  let youtubeVideoId: string | null = null;
  let activeCueId: string | null = null;
  let renderedSignature: string | null = null;
  let quickFixMode = false;
  let editingCueId: string | null = null;
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
      cueRevision,
      dom.transcriptSearch.value,
      settings?.showRomanization ?? false,
      settings?.showTranslation ?? false,
      settings?.interfaceLocale,
      languages,
      partial,
      quickFixMode,
      editingCueId,
      editingKey(),
    ]);
  }

  function render(): void {
    if (!settings) return;
    const total = cues.length;
    const signature = renderSignature();
    const partialTotal = partialCues.length;
    dom.transcriptStatus.textContent = partial
      ? partialTotal === 0 ? '' : t("{value1} cues · Still generating", {value1: partialTotal})
      : total === 0 ? '' : t("{value1} cues", {value1: total});
    if (signature === renderedSignature) return;
    renderedSignature = signature;
    const scrollTop = dom.transcriptList.scrollTop;
    const previousInput = quickFixInputElement();
    const editorSelection = !focusQuickFixEditor && previousInput === dom.transcriptList.ownerDocument.activeElement && previousInput
      ? { start: previousInput.selectionStart, end: previousInput.selectionEnd,
        direction: previousInput.selectionDirection, scrollLeft: previousInput.scrollLeft }
      : null;
    dom.transcriptList.innerHTML = (partial ? partialTotal : total) === 0
      ? `<p class="transcript-empty muted">${escapeHtml(t("Generate subtitles to see the transcript."))}</p>`
      : partial ? panelPartialTranscriptListHtml({
        cues: partialCues,
        activeCueId,
        query: dom.transcriptSearch.value,
        searchableText,
        languages,
      }) : panelTranscriptListHtml({
        cues,
        activeCueId,
        query: dom.transcriptSearch.value,
        searchableText,
        languages,
        settings,
        quickFixMode,
        editingCueId,
        quickFixEditing: quickFixEditing
          ? { cueId: quickFixEditing.cueId, tokenIndex: quickFixEditing.tokenIndex, value: quickFixDraft }
          : null,
      });
    hydrateQuickFixEditor();
    const input = quickFixInputElement();
    if (input && editorSelection) {
      input.focus({ preventScroll: true });
      input.setSelectionRange(editorSelection.start, editorSelection.end, editorSelection.direction ?? undefined);
      input.scrollLeft = editorSelection.scrollLeft;
    }
    dom.transcriptList.scrollTop = scrollTop;
  }

  /** Restore the inline editor's live state after a rebuild; typing never rebuilds. */
  function hydrateQuickFixEditor(): void {
    const input = quickFixInputElement();
    if (!input) return;
    if (input.value !== quickFixDraft) input.value = quickFixDraft;
    updateQuickFixEditor();
    if (focusQuickFixEditor) {
      focusQuickFixEditor = false;
      input.focus({ preventScroll: true });
      input.select();
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
    save.textContent = quickFixBusy ? t("Saving…") : t("Save correction");
    editor.setAttribute('aria-busy', String(quickFixBusy));

    if (quickFixBusy) {
      hint.textContent = t("Refreshing translation and word data…");
      hint.classList.remove('error');
    } else if (quickFixError) {
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

  dom.transcriptSearch.addEventListener('input', () => {
    if (searchRenderPending) return;
    searchRenderPending = true;
    dom.transcriptSearch.ownerDocument.defaultView!.requestAnimationFrame(() => {
      searchRenderPending = false;
      render();
    });
  });
  dom.transcriptList.addEventListener('click', (event) => {
    const button = (event.target as Element)?.closest<HTMLButtonElement>('[data-transcript-action]');
    const cueId = button?.dataset.cueId;
    if (!button) return;
    const action = button.dataset.transcriptAction;
    if (quickFixBusy && action?.startsWith('quick-fix-')) return;
    if (action === 'quick-fix-line' && cueId) {
      if (quickFixEditing) return;
      editingCueId = editingCueId === cueId ? null : cueId;
      render();
      const row = dom.transcriptList.querySelector(`[data-cue-id="${cssAttributeValue(cueId)}"]`);
      row?.querySelector<HTMLButtonElement>('[data-transcript-action="quick-fix-token"], [data-transcript-action="quick-fix-line"]')?.focus({ preventScroll: true });
      return;
    }
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
      if (youtubeVideoId) dom.onSeekToCue?.(cueId, 'jump');
      ackButton(button);
    } else if (action === 'copy') {
      const text = (partial ? partialCues : cues).find((c) => c.cueId === cueId)?.sourceText;
      if (text && navigator.clipboard) {
        void navigator.clipboard.writeText(text)
          .then(() => ackButton(button, t("Copied")))
          .catch(() => ackButton(button, t("Failed")));
      } else {
        ackButton(button, t("Failed"));
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
    if (quickFixBusy) return;
    if (event.key === 'Enter') {
      event.preventDefault();
      trySaveQuickFix();
    } else if (event.key === 'Escape') {
      event.preventDefault();
      dom.onQuickFixCancel?.();
    }
  });

  return {
    setData(nextYoutubeVideoId: string | null, nextCues: readonly SubtitleCue[], nextSettings: ExtensionSettings, nextLanguages?: TranscriptLanguages) {
      if (youtubeVideoId !== nextYoutubeVideoId || !nextCues.some((cue) => cue.cueId === editingCueId)) editingCueId = null;
      const signature = JSON.stringify(nextCues);
      if (partial || signature !== cueContentSignature) searchableText = nextCues.map(transcriptSearchText);
      if (signature !== cueContentSignature) cueRevision += 1;
      cueContentSignature = signature;
      youtubeVideoId = nextYoutubeVideoId; cues = nextCues; partialCues = []; partial = false; settings = nextSettings; languages = nextLanguages; render();
    },
    setPartialData(nextYoutubeVideoId: string, nextCues: readonly PartialSubtitleCue[], nextSettings: ExtensionSettings, nextLanguages?: TranscriptLanguages) {
      const signature = JSON.stringify(nextCues);
      if (!partial || signature !== cueContentSignature) searchableText = nextCues.map((cue) => cue.sourceText.toLowerCase());
      if (signature !== cueContentSignature) cueRevision += 1;
      cueContentSignature = signature;
      youtubeVideoId = nextYoutubeVideoId; cues = []; partialCues = nextCues; partial = true; settings = nextSettings; languages = nextLanguages; render();
    },
    setActiveCue(cueId: string | null) {
      if (cueId === activeCueId) return;
      activeCueId = cueId;
      applyActiveCue(cueId);
    },
    setQuickFixMode(enabled: boolean) {
      if (quickFixMode === enabled) return;
      quickFixMode = enabled;
      if (!enabled) editingCueId = null;
      render();
    },
    /** Open the inline editor on a token, or close it with null. Focuses the input on open. */
    setQuickFixEditing(selection: QuickFixSelection | null) {
      const nextKey = selection ? `${selection.cueId}:${selection.tokenIndex}` : null;
      if (nextKey === editingKey()) return;
      quickFixEditing = selection;
      if (selection) editingCueId = selection.cueId;
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

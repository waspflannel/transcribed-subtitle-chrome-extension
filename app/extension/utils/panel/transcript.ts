import type { PartialSubtitleCue, SubtitleCue } from '../contracts';
import type { ExtensionSettings } from '../settings-model';
import { escapeHtml } from '../html';

/** The token currently being edited inline in Quick fix mode. */
export interface QuickFixEditing {
  cueId: string;
  tokenIndex: number;
  /** Current draft shown in the inline input (the original token text when opened). */
  value: string;
}

export function filterTranscriptCues(cues: readonly SubtitleCue[], query: string): SubtitleCue[] {
  const q = query.trim().toLowerCase();
  if (q === '') return [...cues];
  return cues.filter((cue) => transcriptSearchText(cue).includes(q));
}

function transcriptSearchText(cue: SubtitleCue): string {
  return [
    cue.sourceText,
    cue.romanization ?? '',
    cue.translatedText,
    ...cue.tokens.flatMap((t) => [t.text, t.normalizedText, t.romanization ?? '', t.gloss ?? '', t.translation ?? '']),
  ].join(' ').toLowerCase();
}

function timecode(ms: number): string {
  const s = Math.max(0, Math.floor(ms / 1000));
  return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
}

export function panelTranscriptListHtml(input: {
  cues: readonly SubtitleCue[];
  activeCueId: string | null;
  query: string;
  settings: ExtensionSettings;
  quickFixMode?: boolean;
  editingCueId?: string | null;
  quickFixEditing?: QuickFixEditing | null;
}): string {
  const cues = filterTranscriptCues(input.cues, input.query);
  if (cues.length === 0) {
    return '<p class="transcript-empty muted">No cues match that search.</p>';
  }
  return cues.map((cue) => transcriptRow(cue, cue.cueId === input.activeCueId, input.settings, input.quickFixMode ?? false, input.editingCueId ?? null, input.quickFixEditing ?? null)).join('');
}

export function panelPartialTranscriptListHtml(input: {
  cues: readonly PartialSubtitleCue[];
  activeCueId: string | null;
  query: string;
}): string {
  const query = input.query.trim().toLowerCase();
  const cues = query === ''
    ? input.cues
    : input.cues.filter((cue) => cue.sourceText.toLowerCase().includes(query));

  if (cues.length === 0) return '<p class="transcript-empty muted">No cues match that search.</p>';

  return cues.map((cue) => `
    <article class="cue${cue.cueId === input.activeCueId ? ' on' : ''}" role="listitem" aria-current="${cue.cueId === input.activeCueId ? 'true' : 'false'}" data-cue-id="${escapeHtml(cue.cueId)}">
      <div class="tc">${escapeHtml(timecode(cue.startMs))}</div>
      <div class="cbody">
        <div class="ct">${escapeHtml(cue.sourceText)}</div>
        <div class="cue-actions">
          <button type="button" class="cue-action" data-transcript-action="copy" data-cue-id="${escapeHtml(cue.cueId)}" aria-label="Copy cue ${cue.index + 1}">Copy</button>
        </div>
      </div>
    </article>`).join('');
}

function transcriptRow(cue: SubtitleCue, active: boolean, settings: ExtensionSettings, quickFixMode: boolean, editingCueId: string | null, quickFixEditing: QuickFixEditing | null): string {
  const tr = settings.showTranslation && cue.translatedText.trim() !== cue.sourceText.trim()
    ? `<div class="cg">${escapeHtml(cue.translatedText || 'Translation unavailable')}</div>` : '';
  return `
    <article class="cue${active ? ' on' : ''}" role="listitem" aria-current="${active ? 'true' : 'false'}" data-cue-id="${escapeHtml(cue.cueId)}">
      <div class="tc">${escapeHtml(timecode(cue.startMs))}</div>
      <div class="cbody">
        ${sourceLineHtml(cue, settings, quickFixMode && editingCueId === cue.cueId)}
        ${tr}
        ${quickFixMode && editingCueId === cue.cueId ? (quickFixEditing?.cueId === cue.cueId ? quickFixEditorHtml(quickFixEditing.value) : '<p class="microcopy">Select a word above to correct it.</p>') : ''}
        <div class="cue-actions">
          ${quickFixMode ? `<button type="button" class="cue-action" data-transcript-action="quick-fix-line" data-cue-id="${escapeHtml(cue.cueId)}" aria-expanded="${editingCueId === cue.cueId}" ${quickFixEditing ? 'disabled' : ''}>${editingCueId === cue.cueId ? 'Done' : 'Edit'}</button>` : ''}
          <button type="button" class="cue-action" data-transcript-action="jump" data-cue-id="${escapeHtml(cue.cueId)}" aria-label="Jump to cue ${cue.index + 1}">Jump</button>
          <button type="button" class="cue-action" data-transcript-action="copy" data-cue-id="${escapeHtml(cue.cueId)}" aria-label="Copy cue ${cue.index + 1}">Copy</button>
        </div>
      </div>
    </article>`;
}

/**
 * Source line for a cue. When romanization is enabled and the cue's tokens
 * carry per-word readings, each word gets its romaji riding above it (the
 * marketing site's player-mock motif); otherwise fall back to the plain
 * source line with an optional whole-line romanization underneath.
 *
 * Quick fix mode keeps the exact token rendering (readings, spacing) and only
 * turns each token into a button. The correction form sits below the line
 * so selecting a word never removes it or inserts a form between words.
 */
function sourceLineHtml(cue: SubtitleCue, settings: ExtensionSettings, quickFixMode: boolean): string {
  if (quickFixMode) {
    const tokens = cue.tokens
      .map((token) => {
        const reading = token.romanization ? `<small>${escapeHtml(token.romanization)}</small>` : '';

        return `<button type="button" class="tok tok-edit" data-transcript-action="quick-fix-token" data-cue-id="${escapeHtml(cue.cueId)}" data-token-index="${token.index}" aria-label="Edit source token ${escapeHtml(token.text)}">${escapeHtml(token.text)}${reading}</button>`;
      })
      .join('');

    return `<div class="toks">${tokens}</div>`;
  }

  const useTokens = settings.showRomanization && cue.tokens.some((token) => typeof token.romanization === 'string' && token.romanization !== '');

  if (!useTokens) {
    const rom = settings.showRomanization && cue.romanization
      ? `<div class="cr">${escapeHtml(cue.romanization)}</div>` : '';

    return `<div class="ct">${escapeHtml(cue.sourceText)}</div>${rom}`;
  }

  const tokens = cue.tokens
    .map((token) => {
      const reading = token.romanization ? `<small>${escapeHtml(token.romanization)}</small>` : '';

      return `<span class="tok">${escapeHtml(token.text)}${reading}</span>`;
    })
    .join('');

  return `<div class="toks">${tokens}</div>`;
}

/** Correction form beneath the selected source line. */
function quickFixEditorHtml(value: string): string {
  return `
    <div class="tok-editor" data-quick-fix-editor>
      <label for="quick-fix-text">Correct word</label>
      <input id="quick-fix-text" type="text" name="quickFixText" autocomplete="off" spellcheck="false" data-quick-fix-input value="${escapeHtml(value)}" aria-label="Replacement token or phrase" />
      <p class="microcopy">Updates this line’s translation, pronunciation, and word cards.</p>
      <span class="tok-editor-row">
        <span class="tok-editor-hint" data-quick-fix-hint role="status" aria-live="polite"></span>
        <button type="button" class="cue-action" data-transcript-action="quick-fix-save">Save correction</button>
        <button type="button" class="cue-action" data-transcript-action="quick-fix-cancel">Cancel</button>
      </span>
    </div>`;
}

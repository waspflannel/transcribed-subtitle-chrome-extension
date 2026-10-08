import { t } from '../i18n';
import type { PartialSubtitleCue, SubtitleCue } from '../contracts';
import type { ExtensionSettings } from '../settings-model';
import { escapeHtml } from '../html';

/** BCP-47 tags for transcript text, so CJK lines render with the right glyphs. */
export interface TranscriptLanguages {
  source: string;
  target: string;
}

function langAttribute(tag: string | undefined): string {
  return tag ? ` lang="${escapeHtml(tag)}"` : '';
}

/** The token currently being edited inline in Quick fix mode. */
export interface QuickFixEditing {
  cueId: string;
  tokenIndex: number;
  /** Current draft shown in the inline input (the original token text when opened). */
  value: string;
}

export function filterTranscriptCues(cues: readonly SubtitleCue[], query: string, searchableText?: readonly string[]): SubtitleCue[] {
  const q = query.trim().toLowerCase();
  if (q === '') return [...cues];
  return cues.filter((cue, index) => (searchableText?.[index] ?? transcriptSearchText(cue)).includes(q));
}

export function transcriptSearchText(cue: SubtitleCue): string {
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
  searchableText?: readonly string[];
  settings: ExtensionSettings;
  quickFixMode?: boolean;
  editingCueId?: string | null;
  quickFixEditing?: QuickFixEditing | null;
  languages?: TranscriptLanguages;
}): string {
  const cues = filterTranscriptCues(input.cues, input.query, input.searchableText);
  if (cues.length === 0) {
    return `<p class="transcript-empty muted">${escapeHtml(t("No cues match that search."))}</p>`;
  }
  return cues.map((cue) => transcriptRow(cue, cue.cueId === input.activeCueId, input.settings, input.quickFixMode ?? false, input.editingCueId ?? null, input.quickFixEditing ?? null, input.languages)).join('');
}

export function panelPartialTranscriptListHtml(input: {
  cues: readonly PartialSubtitleCue[];
  activeCueId: string | null;
  query: string;
  searchableText?: readonly string[];
  languages?: TranscriptLanguages;
}): string {
  const query = input.query.trim().toLowerCase();
  const cues = query === ''
    ? input.cues
    : input.cues.filter((cue, index) => (input.searchableText?.[index] ?? cue.sourceText.toLowerCase()).includes(query));

  if (cues.length === 0) return `<p class="transcript-empty muted">${escapeHtml(t("No cues match that search."))}</p>`;

  return cues.map((cue) => `
    <article class="cue${cue.cueId === input.activeCueId ? ' on' : ''}" role="listitem" aria-current="${cue.cueId === input.activeCueId ? 'true' : 'false'}" data-cue-id="${escapeHtml(cue.cueId)}">
      <div class="tc">${escapeHtml(timecode(cue.startMs))}</div>
      <div class="cbody">
        <div class="ct" dir="auto"${langAttribute(input.languages?.source)}>${escapeHtml(cue.sourceText)}</div>
        <div class="cue-actions">
          <button type="button" class="cue-action" data-transcript-action="copy" data-cue-id="${escapeHtml(cue.cueId)}" aria-label="${escapeHtml(t('Copy cue {number}', {number: cue.index + 1}))}">${escapeHtml(t("Copy"))}</button>
        </div>
      </div>
    </article>`).join('');
}

function transcriptRow(cue: SubtitleCue, active: boolean, settings: ExtensionSettings, quickFixMode: boolean, editingCueId: string | null, quickFixEditing: QuickFixEditing | null, languages: TranscriptLanguages | undefined): string {
  const tr = settings.showTranslation && cue.translatedText.trim() !== cue.sourceText.trim()
    ? cue.translatedText
      ? `<div class="cg" dir="auto"${langAttribute(languages?.target)}>${escapeHtml(cue.translatedText)}</div>`
      : `<div class="cg" dir="auto">${escapeHtml(t("Translation unavailable"))}</div>`
    : '';
  return `
    <article class="cue${active ? ' on' : ''}" role="listitem" aria-current="${active ? 'true' : 'false'}" data-cue-id="${escapeHtml(cue.cueId)}">
      <div class="tc">${escapeHtml(timecode(cue.startMs))}</div>
      <div class="cbody">
        ${sourceLineHtml(cue, settings, quickFixMode && editingCueId === cue.cueId, langAttribute(languages?.source))}
        ${tr}
        ${quickFixMode && editingCueId === cue.cueId ? (quickFixEditing?.cueId === cue.cueId ? quickFixEditorHtml(quickFixEditing.value) : `<p class="microcopy">${escapeHtml(t("Select a word above to correct it."))}</p>`) : ''}
        <div class="cue-actions">
          ${quickFixMode ? `<button type="button" class="cue-action" data-transcript-action="quick-fix-line" data-cue-id="${escapeHtml(cue.cueId)}" aria-expanded="${editingCueId === cue.cueId}" ${quickFixEditing ? 'disabled' : ''}>${editingCueId === cue.cueId ? t("Done") : t("Edit")}</button>` : ''}
          <button type="button" class="cue-action" data-transcript-action="jump" data-cue-id="${escapeHtml(cue.cueId)}" aria-label="${escapeHtml(t('Jump to cue {number}', {number: cue.index + 1}))}">${escapeHtml(t("Jump"))}</button>
          <button type="button" class="cue-action" data-transcript-action="copy" data-cue-id="${escapeHtml(cue.cueId)}" aria-label="${escapeHtml(t('Copy cue {number}', {number: cue.index + 1}))}">${escapeHtml(t("Copy"))}</button>
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
function sourceLineHtml(cue: SubtitleCue, settings: ExtensionSettings, quickFixMode: boolean, lang: string): string {
  if (quickFixMode) {
    const tokens = cue.tokens
      .map((token) => {
        const reading = token.romanization ? `<small dir="ltr">${escapeHtml(token.romanization)}</small>` : '';

        return `<button type="button" class="tok tok-edit" data-transcript-action="quick-fix-token" data-cue-id="${escapeHtml(cue.cueId)}" data-token-index="${token.index}" aria-label="${escapeHtml(t('Edit source token {word}', {word: token.text}))}"><span class="tok-text">${escapeHtml(token.text)}</span>${reading}</button>`;
      })
      .join('');

    return `<div class="toks" dir="auto"${lang}>${tokens}</div>`;
  }

  const useTokens = settings.showRomanization && cue.tokens.some((token) => typeof token.romanization === 'string' && token.romanization !== '');

  if (!useTokens) {
    const rom = settings.showRomanization && cue.romanization
      ? `<div class="cr" dir="ltr">${escapeHtml(cue.romanization)}</div>` : '';

    return `<div class="ct" dir="auto"${lang}>${escapeHtml(cue.sourceText)}</div>${rom}`;
  }

  const tokens = cue.tokens
    .map((token) => {
      const reading = token.romanization ? `<small dir="ltr">${escapeHtml(token.romanization)}</small>` : '';
      // Punctuation-only tokens (、。！？) attach to the previous word instead of sitting after a word gap.
      const punctuation = /^\p{P}+$/u.test(token.text.trim()) ? ' tok--punct' : '';

      return `<span class="tok${punctuation}"><span class="tok-text">${escapeHtml(token.text)}</span>${reading}</span>`;
    })
    .join('');

  return `<div class="toks" dir="auto"${lang}>${tokens}</div>`;
}

/** Correction form beneath the selected source line. */
function quickFixEditorHtml(value: string): string {
  return `
    <div class="tok-editor" data-quick-fix-editor>
      <label for="quick-fix-text">${escapeHtml(t("Correct word"))}</label>
      <input id="quick-fix-text" type="text" dir="auto" name="quickFixText" autocomplete="off" spellcheck="false" data-quick-fix-input value="${escapeHtml(value)}" aria-label="${escapeHtml(t("Replacement token or phrase"))}" />
      <p class="microcopy">${escapeHtml(t("Updates this line’s translation, pronunciation, and word cards."))}</p>
      <span class="tok-editor-row">
        <span class="tok-editor-hint" data-quick-fix-hint role="status" aria-live="polite"></span>
        <button type="button" class="cue-action" data-transcript-action="quick-fix-save">${escapeHtml(t("Save correction"))}</button>
        <button type="button" class="cue-action" data-transcript-action="quick-fix-cancel">${escapeHtml(t("Cancel"))}</button>
      </span>
    </div>`;
}

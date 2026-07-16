import type { SubtitleCue } from '../contracts';
import type { ExtensionSettings } from '../settings-model';
import { escapeHtml } from '../html';

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
}): string {
  const cues = filterTranscriptCues(input.cues, input.query);
  if (cues.length === 0) {
    return '<p class="transcript-empty muted">No cues match that search.</p>';
  }
  return cues.map((cue) => transcriptRow(cue, cue.cueId === input.activeCueId, input.settings)).join('');
}

function transcriptRow(cue: SubtitleCue, active: boolean, settings: ExtensionSettings): string {
  const tr = settings.showTranslation && cue.translatedText.trim() !== cue.sourceText.trim()
    ? `<div class="cg">${escapeHtml(cue.translatedText)}</div>` : '';
  return `
    <article class="cue${active ? ' on' : ''}" role="listitem" aria-current="${active ? 'true' : 'false'}" data-cue-id="${escapeHtml(cue.cueId)}">
      <div class="tc">${escapeHtml(timecode(cue.startMs))}</div>
      <div class="cbody">
        ${sourceLineHtml(cue, settings)}
        ${tr}
        <div class="cue-actions">
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
 */
function sourceLineHtml(cue: SubtitleCue, settings: ExtensionSettings): string {
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

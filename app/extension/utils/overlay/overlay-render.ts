import type { ExtensionSettings } from '../settings-model';
import { escapeHtml } from '../html';
import type { LearningToken, SubtitleCue } from '../contracts';
import { hasLearningMetadata, tokenKey } from '../track-tokens';
import { EMPTY_INTERACTION, type OverlayInteractionState, type OverlayRenderState } from './types';

export function renderOverlayContent(
  state: OverlayRenderState,
  interaction: OverlayInteractionState = EMPTY_INTERACTION,
): string {
  const renderFrame = (railHtml: string): string => {
    return state.settings.overlayVisible ? railHtml : '';
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
          <div class="token-area" dir="auto" lang="${
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
  } dir="auto">${escapeHtml(cue.translatedText)}</div>`;
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
  const rows: { label: string; value: string }[] = [{ label: 'Text', value: token.text }];

  if (settings.showRomanization) {
    rows.push({ label: 'Romanization', value: token.romanization ?? '' });
  }

  rows.push({ label: 'Translation', value: token.translation ?? '' });

  if (settings.showGloss) {
    rows.push({ label: 'Gloss', value: token.gloss ?? '' });
  }

  rows.push({ label: 'Part of speech', value: token.partOfSpeech ?? '' });
  rows.push({ label: 'Lemma', value: token.lemma ?? '' });
  rows.push({ label: 'Root', value: token.root ?? '' });
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


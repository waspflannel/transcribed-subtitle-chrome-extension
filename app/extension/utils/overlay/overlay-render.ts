import type { ExtensionSettings } from '../settings-model';
import { escapeHtml } from '../html';
import type { LearningToken, SubtitleCue } from '../contracts';
import { hasLearningMetadata, tokenKey } from '../track-tokens';
import { EMPTY_INTERACTION, type OverlayInteractionState, type OverlayRenderState, type OverlayStatus } from './types';

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


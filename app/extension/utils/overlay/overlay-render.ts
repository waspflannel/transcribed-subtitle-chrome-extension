import { t } from '../i18n';
import type { ExtensionSettings } from '../settings-model';
import { escapeHtml } from '../html';
import { languageTag } from '../languages';
import type { LearningToken, PartialSubtitleCue, SubtitleCue } from '../contracts';
import type { PartialSubtitleTrack } from '../messages';
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
      eyebrow: t("AI subtitles"),
      title: t("Unsupported page"),
      detail:
        state.page.reason === 'missing_video_id' || state.page.reason === 'invalid_video_id'
          ? t("The current YouTube URL has no valid video ID.")
          : t("YouTube video or Short required."),
      meta: [],
    }));
  }

  if (state.bindingError) {
    return renderFrame(renderBindingError(state.bindingError));
  }

  if (state.subtitleState.type === 'ready') {
    const cue = state.activeCue;
    const track = state.subtitleState.track;

    if (!cue) {
      return interaction.actionStatus
        ? renderFrame(renderShell({
            eyebrow: t("AI subtitles"),
            title: interaction.actionStatus.message,
            detail: t("There is no subtitle cue at the current playback position."),
            meta: [],
          }))
        : renderFrame('');
    }

    const cueRomanization =
      state.settings.showRomanization && cue.romanization
        ? `<div class="cue-romanization study-cue-romanization${studyBlurClass(
            state.settings.blurRomanization,
            'romanization',
          )}"${state.settings.blurRomanization
              ? ` tabindex="0" aria-label="${escapeHtml(t('Cue romanization, focus to reveal blurred text'))}"`
              : ''}>${escapeHtml(cue.romanization)}</div>`
        : '';

    return renderFrame(`
      <section class="rail" role="status" data-study-rail>
        <div class="rail-meta">
          <span class="eyebrow">${escapeHtml(t("AI subtitles"))}</span>
          <span class="cue-time">${escapeHtml(formatCueTimeRange(cue))}</span>
        </div>
        <div class="rail-main">
          <div class="token-area" dir="auto" lang="${escapeHtml(languageTag(track.detectedSourceLanguage ?? track.sourceLanguage))}">${renderSourceLine(cue, state.settings, interaction)}</div>
          ${cueRomanization}
          ${renderTranslation(cue.sourceText, cue.translatedText, track.targetLanguage, state.settings)}
        </div>
        ${renderStudyControls(interaction.copyStatus, interaction.actionStatus)}
      </section>
    `);
  }

  if (state.subtitleState.type === 'error') {
    return renderFrame(renderShell({
      eyebrow: t("AI subtitles"),
      title: t("Subtitle generation failed"),
      detail: t(state.subtitleState.message),
      meta: [t("Video {value1}", {value1: state.page.videoId})],
    }));
  }

  if (state.subtitleState.type === 'loading') {
    const partialCue = state.activePartialCue;

    if (partialCue && state.subtitleState.partialTrack) {
      return renderFrame(renderPartialRail(partialCue, state.subtitleState.partialTrack, state.settings));
    }

    return renderFrame(`
      <section class="rail rail--message rail--generating" role="status" aria-label="${escapeHtml(t("Generating subtitles"))}">
        <div class="title">${escapeHtml(t("Generating"))}</div>
      </section>
    `);
  }

  return renderFrame(renderShell({
    eyebrow: t("AI subtitles"),
    title: t("No generated track"),
    detail: t("This video does not have a generated subtitle track yet."),
    meta: [t("Video {value1}", {value1: state.page.videoId})],
  }));
}

/**
 * Passive rail for a cue from a still-running job: source text immediately,
 * romanization and translation as their batches land. No token buttons or
 * study controls -- word cards need the finalized track.
 */
function renderPartialRail(
  cue: PartialSubtitleCue,
  partialTrack: PartialSubtitleTrack,
  settings: ExtensionSettings,
): string {
  const cueRomanization =
    settings.showRomanization && cue.romanization
      ? `<div class="cue-romanization study-cue-romanization${studyBlurClass(
          settings.blurRomanization,
          'romanization',
        )}"${settings.blurRomanization
            ? ` tabindex="0" aria-label="${escapeHtml(t('Cue romanization, focus to reveal blurred text'))}"`
            : ''}>${escapeHtml(cue.romanization)}</div>`
      : '';
  const translation = renderTranslation(cue.sourceText, cue.translatedText ?? '', partialTrack.targetLanguage, settings);

  return `
    <section class="rail" role="status">
      <div class="rail-meta">
        <span class="eyebrow">${escapeHtml(t("AI subtitles · still generating"))}</span>
        <span class="cue-time">${escapeHtml(formatCueTimeRange(cue))}</span>
      </div>
      <div class="rail-main">
        <div class="token-area" dir="auto" lang="${escapeHtml(languageTag(partialTrack.sourceLanguage))}"><span class="partial-source-layer token-text${studyBlurClass(settings.blurSourceWords, 'token')}"${settings.blurSourceWords
            ? ` tabindex="0" aria-label="${escapeHtml(t('Partial source text, focus to reveal blurred text'))}"`
            : ''}>${escapeHtml(cue.sourceText)}</span></div>
        ${cueRomanization}
        ${translation}
      </div>
    </section>
  `;
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
          <button class="token-card" type="button" data-token-index="${token.index}" data-focus-key="${escapeHtml(
            `${cue.cueId}:${token.index}`,
          )}" aria-pressed="${isPinned ? 'true' : 'false'}" aria-label="${escapeHtml(t('Study word: {word}', {word: token.text}))}">
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
    throw new TypeError(t("Pinned token {value1} is not present on cue {value2}.", {value1: String(selectedIndex), value2: cue.cueId}));
  }

  const key = tokenKey(cue.cueId, token.index);
  const hasMetadata = hasLearningMetadata(token);
  const isPending = interactionTokenSet(interaction, 'pending')?.has(key) ?? false;
  const isFailed = interactionTokenSet(interaction, 'failed')?.has(key) ?? false;

  if (!hasMetadata || isPending || isFailed) {
    const detail = isFailed
      ? t("Word card generation failed. Select the word again to retry.")
      : t("Loading word card...");

    return `
      <div class="token-popover">
        <div class="token-popover-header">
          <span class="token-popover-title">${escapeHtml(token.text)}</span>
          <button class="icon-button" type="button" data-close-token-detail data-focus-key="token-detail-close" data-return-focus-key="${escapeHtml(
            `${cue.cueId}:${token.index}`,
          )}" aria-label="${escapeHtml(t("Close token detail"))}">x</button>
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
        <button class="icon-button" type="button" data-close-token-detail data-focus-key="token-detail-close" data-return-focus-key="${escapeHtml(
          `${cue.cueId}:${token.index}`,
        )}" aria-label="${escapeHtml(t("Close token detail"))}">x</button>
      </div>
      <div class="token-fields">${rows}</div>
    </div>
  `;
}

function renderTranslation(
  sourceText: string,
  translatedText: string,
  targetLanguage: string | undefined,
  settings: ExtensionSettings,
): string {
  const text = translatedText.trim();

  if (!settings.showTranslation || text === '' || text === sourceText.trim()) {
    return '';
  }

  return `<div class="translation study-translation${studyBlurClass(settings.blurTranslation, 'translation')}"${settings.blurTranslation ? ` tabindex="0" aria-label="${escapeHtml(t('Cue translation, focus to reveal blurred text'))}"` : ''} dir="auto" lang="${escapeHtml(languageTag(targetLanguage))}">${escapeHtml(text)}</div>`;
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
        message: copyStatus === 'copied' ? t("Copied") : t("Copy failed"),
        tone: copyStatus === 'copied' ? 'success' : 'error',
      }
    : actionStatus;
  const statusClass = copyStatus ?? visibleStatus?.tone;
  const status = visibleStatus
    ? `<span class="control-status ${statusClass}" role="status">${escapeHtml(t(visibleStatus.message))}</span>`
    : '';

  return `
    <div class="rail-controls" aria-label="${escapeHtml(t("Subtitle study controls"))}">
      <button class="study-control" type="button" data-study-control="replay">${escapeHtml(t("Replay"))}</button>
      <button class="study-control" type="button" data-study-control="copy">${escapeHtml(t("Copy"))}</button>
      ${status}
    </div>
  `;
}

function formatCueTimeRange(cue: Pick<SubtitleCue, 'startMs' | 'endMs'>): string {
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
  const rows: { label: string; value: string }[] = [{ label: t("Text"), value: token.text }];

  if (settings.showRomanization) {
    rows.push({ label: t("Romanization"), value: token.romanization ?? '' });
  }

  rows.push({ label: t("Translation"), value: token.translation ?? '' });

  if (settings.showGloss) {
    rows.push({ label: t("Gloss"), value: token.gloss ?? '' });
  }

  rows.push({ label: t("Part of speech"), value: token.partOfSpeech ?? '' });
  rows.push({ label: t("Lemma"), value: token.lemma ?? '' });
  rows.push({ label: t("Root"), value: token.root ?? '' });
  rows.push({ label: t("Usage note"), value: token.usageNote ?? '' });

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

function renderBindingError(detail: string): string {
  return `
    <section class="rail rail--message" role="alert">
      <div class="rail-meta">
        <span class="eyebrow">${escapeHtml(t("AI subtitles"))}</span>
      </div>
      <div class="rail-main rail-main--message">
        <div class="title">${escapeHtml(t("Subtitle display needs a retry"))}</div>
        <div class="detail">${escapeHtml(detail)}</div>
      </div>
      <div class="rail-controls">
        <button class="study-control" type="button" data-retry-binding>${escapeHtml(t("Retry attachment"))}</button>
      </div>
    </section>
  `;
}


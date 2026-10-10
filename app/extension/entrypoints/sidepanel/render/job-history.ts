import { t } from '../../../utils/i18n';
import { aiProviderLabel } from '../../../utils/settings-model';
import { escapeHtml } from '../../../utils/html';
import { groupJobHistoryByMediaKind, jobHistoryMediaKind } from '../../../utils/job-history-media';
import { languageLabel } from '../../../utils/languages';
import type { PanelState } from '../../../utils/messages';
import { formatHistoryTimestamp, generationProgress } from '../../../utils/panel-progress';
import { formatDurationSeconds, formatJobTiming, publicJobTelemetry } from '../../../utils/job-telemetry';

export function renderJobHistory(
  state: PanelState,
  elements: { jobsList: HTMLElement; jobsError: HTMLElement; cancellationBusy?: boolean },
): void {
  if (state.jobHistoryError) {
    elements.jobsError.hidden = false;
    elements.jobsError.textContent = t(state.jobHistoryError);
  } else {
    elements.jobsError.hidden = true;
    elements.jobsError.textContent = '';
  }

  if (state.jobHistory.length === 0) {
    elements.jobsList.innerHTML = `<p class="empty-state">${escapeHtml(t("Nothing generated yet. Videos you generate subtitles for show up here."))}</p>`;

    return;
  }

  const groups = groupJobHistoryByMediaKind(state.jobHistory);

  elements.jobsList.innerHTML = [
    jobHistorySectionHtml(t("Videos"), groups.videos, state, elements.cancellationBusy ?? false),
    jobHistorySectionHtml(t("Shorts"), groups.shorts, state, elements.cancellationBusy ?? false),
  ].join('');
}

function jobHistorySectionHtml(title: string, jobs: PanelState['jobHistory'], state: PanelState, cancellationBusy: boolean): string {
  if (jobs.length === 0) {
    return '';
  }

  return `
    <section class="job-section" aria-label="${escapeHtml(title)}">
      <div class="job-section-heading">
        <h3>${escapeHtml(title)}</h3>
        <span>${jobs.length}</span>
      </div>
      <div class="job-section-list">${jobs.map((job) => jobHistoryItemHtml(job, state, cancellationBusy)).join('')}</div>
    </section>
  `;
}

/** Video-first history card: title, plain-word status, compact meta, and the actions people actually take. */
function jobHistoryItemHtml(job: PanelState['jobHistory'][number], state: PanelState, cancellationBusy: boolean): string {
  const telemetry = publicJobTelemetry(job);
  const progress = generationProgress(job);
  const meta = [
    `${aiProviderLabel(job.aiProvider)}${job.aiProvider === 'codex' || job.aiProvider === 'claude' ? '' : ' API'} · ${job.aiModel}`,
    job.aiFastMode ? t('Fast mode') : null,
    `${languageLabel(job.sourceLanguage)} → ${languageLabel(job.targetLanguage)}`,
    job.detectedSourceLanguage ? t('detected {language}', { language: languageLabel(job.detectedSourceLanguage) }) : null,
    formatDurationSeconds(telemetry.videoDurationSeconds),
    jobHistoryMediaKind(job) === 'short' ? t("Short") : null,
    job.includeTranslation ? t('translation') : null,
    job.includeRomanization ? t('romanization') : null,
    formatJobTiming(job),
    formatHistoryTimestamp(job.completedAt ?? job.lastUpdatedAt ?? job.startedAt),
  ]
    .filter((value): value is string => typeof value === 'string' && value !== '')
    .join(' · ');
  const message = jobMessage(job, progress, telemetry.errorMessage);
  const canCancel = job.status === 'queued' || job.status === 'running';
  const watchMatchesJob = state.activeTabId !== undefined
    && state.subtitleState.type === 'loading'
    && state.subtitleState.jobId === job.jobId
    && state.subtitleState.youtubeVideoId === job.youtubeVideoId;

  return `
    <article class="job-item">
      <a class="job-title job-open-link" href="${escapeHtml(job.youtubeUrl)}" target="_blank" rel="noopener">${escapeHtml(job.youtubeVideoId)}</a>
      <span class="job-pill ${escapeHtml(job.status)}">${escapeHtml(jobPillLabel(job, progress.percent))}</span>
      <p class="job-meta">${escapeHtml(meta)}</p>
      ${message === null ? '' : `<p class="job-message ${job.status === 'failed' ? 'error-copy' : ''}">${escapeHtml(t(message))}</p>`}
      ${job.status === 'failed' ? `<p class="job-message">${escapeHtml(t("Open the video, then review Watch settings before choosing Generate. This job's options are not restored."))}</p>` : ''}
      <div class="job-actions">
        <a class="job-action-button" href="${escapeHtml(job.youtubeUrl)}" target="_blank" rel="noopener noreferrer">${escapeHtml(t("Open video"))}</a>
        ${canCancel ? `<button type="button" class="job-action-button" data-action="cancel-generation" data-job-id="${escapeHtml(job.jobId)}" data-youtube-video-id="${escapeHtml(job.youtubeVideoId)}"${watchMatchesJob ? ` data-tab-id="${state.activeTabId}"` : ''}${cancellationBusy ? ' disabled' : ''}>${cancellationBusy ? t("Cancelling…") : t("Cancel generation")}</button>` : ''}
      </div>
    </article>
  `;
}

function jobPillLabel(job: PanelState['jobHistory'][number], percent: number): string {
  switch (job.status) {
    case 'queued':
      return t("Queued");

    case 'running':
      return `${t('Generating')} · ${percent}%`;

    case 'completed':
      return t("Ready");

    case 'failed':
      return t("Failed");

    case 'cancelled':
      return `${t('Cancelled')} · ${percent}%`;
  }
}

function jobMessage(
  job: PanelState['jobHistory'][number],
  progress: ReturnType<typeof generationProgress>,
  errorMessage: string | undefined,
): string | null {
  if (errorMessage) {
    return errorMessage;
  }

  if (job.status === 'running' || job.status === 'queued') {
    return progress.stageLabel;
  }

  if (job.status === 'cancelled') {
    return `${t(job.message ?? 'Generation cancelled.')} ${progress.stageLabel} · ${t('{percent}% captured.', { percent: progress.percent })}`;
  }

  return null;
}

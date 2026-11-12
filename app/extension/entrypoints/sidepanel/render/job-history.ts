import { t } from '../../../utils/i18n';
import { aiSelectionLabel } from '../../../utils/settings-model';
import { escapeHtml } from '../../../utils/html';
import { groupJobHistoryByMediaKind } from '../../../utils/job-history-media';
import { languageLabel } from '../../../utils/languages';
import type { PanelState } from '../../../utils/messages';
import { formatHistoryTimestamp, generationProgress } from '../../../utils/panel-progress';
import { formatDurationSeconds, publicJobTelemetry } from '../../../utils/job-telemetry';
import { icon } from '../../../utils/panel/icons';

/** Select mode and pending deletes come from the panel; the backend list stays the source of truth. */
export interface JobHistoryView {
  selecting?: boolean;
  selected?: ReadonlySet<string>;
  hidden?: ReadonlySet<string>;
}

export function renderJobHistory(
  state: PanelState,
  elements: { jobsList: HTMLElement; jobsError: HTMLElement; cancellationBusy?: boolean },
  view: JobHistoryView = {},
): void {
  if (state.jobHistoryError) {
    elements.jobsError.hidden = false;
    elements.jobsError.textContent = t(state.jobHistoryError);
  } else {
    elements.jobsError.hidden = true;
    elements.jobsError.textContent = '';
  }

  const jobs = state.jobHistory.filter(job => !view.hidden?.has(job.jobId));
  if (jobs.length === 0) {
    elements.jobsList.innerHTML = `<div class="empty"><span class="empty-glyph">${icon('clock', 24)}</span><p class="empty-title">${escapeHtml(t('Nothing here yet'))}</p><p class="copy">${escapeHtml(t("Nothing generated yet. Videos you generate subtitles for show up here."))}</p></div>`;

    return;
  }

  const groups = groupJobHistoryByMediaKind(jobs);

  elements.jobsList.innerHTML = [
    jobHistorySectionHtml(t("Videos"), groups.videos, state, elements.cancellationBusy ?? false, view),
    jobHistorySectionHtml(t("Shorts"), groups.shorts, state, elements.cancellationBusy ?? false, view),
  ].join('');
}

/** A job can be deleted once nothing is running for it. */
export function isDeletableJob(job: PanelState['jobHistory'][number]): boolean {
  return job.status === 'completed' || job.status === 'failed' || job.status === 'cancelled';
}

function jobHistorySectionHtml(title: string, jobs: PanelState['jobHistory'], state: PanelState, cancellationBusy: boolean, view: JobHistoryView): string {
  if (jobs.length === 0) {
    return '';
  }

  return `
    <section class="job-section" aria-label="${escapeHtml(title)}">
      <div class="job-section-heading">
        <h3>${escapeHtml(title)}</h3>
        <span>${jobs.length}</span>
      </div>
      <div class="job-section-list">${jobs.map((job) => jobHistoryItemHtml(job, state, cancellationBusy, view)).join('')}</div>
    </section>
  `;
}

/** Video-first history card: title, plain-word status, compact meta, and the actions people actually take. */
function jobHistoryItemHtml(job: PanelState['jobHistory'][number], state: PanelState, cancellationBusy: boolean, view: JobHistoryView): string {
  const telemetry = publicJobTelemetry(job);
  const progress = generationProgress(job);
  const meta = [
    aiSelectionLabel(job.aiProvider, job.aiModel),
    job.aiFastMode ? t('Fast mode') : null,
    `${languageLabel(job.detectedSourceLanguage ?? job.sourceLanguage)} → ${languageLabel(job.targetLanguage)}`,
    formatDurationSeconds(telemetry.videoDurationSeconds),
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

  const title = state.videoTitles?.[job.youtubeVideoId] || job.youtubeVideoId;
  const deletable = isDeletableJob(job);
  const selected = view.selected?.has(job.jobId) ?? false;
  const cancelLabel = cancellationBusy ? t("Cancelling…") : t("Cancel generation");

  return `
    <div class="job-wrap" data-job-row="${escapeHtml(job.jobId)}">
    <article class="job-item${selected ? ' selected' : ''}" data-job-id="${escapeHtml(job.jobId)}">
      <span class="job-check">${deletable ? `<input type="checkbox" data-job-select="${escapeHtml(job.jobId)}" aria-label="${escapeHtml(t('Select {name}', { name: title }))}"${selected ? ' checked' : ''}${view.selecting ? '' : ' tabindex="-1"'}>` : ''}</span>
      <div class="job-main">
        <a class="job-title job-open-link" href="${escapeHtml(job.youtubeUrl)}" target="_blank" rel="noopener">${escapeHtml(title)}</a>
        <p class="job-meta">${escapeHtml(meta)}</p>
        ${message === null ? '' : `<p class="job-message ${job.status === 'failed' ? 'error-copy' : ''}">${escapeHtml(t(message))}</p>`}
        ${job.status === 'failed' ? `<p class="job-message">${escapeHtml(t("Open the video, then review Watch settings before choosing Generate. This job's options are not restored."))}</p>` : ''}
      </div>
      <div class="job-side">
        <span class="job-pill ${escapeHtml(job.status)}">${escapeHtml(jobPillLabel(job, progress.percent))}</span>
        <div class="job-actions">
          <a class="icon-btn sm" href="${escapeHtml(job.youtubeUrl)}" target="_blank" rel="noopener noreferrer" title="${escapeHtml(t("Open video"))}">${icon('external', 16)}<span class="sr-only">${escapeHtml(t("Open video"))}</span></a>
          ${canCancel ? `<button type="button" class="icon-btn sm danger" data-action="cancel-generation" data-job-id="${escapeHtml(job.jobId)}" data-youtube-video-id="${escapeHtml(job.youtubeVideoId)}"${watchMatchesJob ? ` data-tab-id="${state.activeTabId}"` : ''}${cancellationBusy ? ' disabled' : ''} title="${escapeHtml(cancelLabel)}">${icon('x', 16)}<span class="sr-only">${escapeHtml(cancelLabel)}</span></button>` : ''}
          ${deletable ? `<button type="button" class="icon-btn sm danger" data-job-delete="${escapeHtml(job.jobId)}" title="${escapeHtml(t('Delete'))}" aria-label="${escapeHtml(t('Delete {name}', { name: title }))}">${icon('trash', 16)}</button>` : ''}
        </div>
      </div>
    </article>
    </div>
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

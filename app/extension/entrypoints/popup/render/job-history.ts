import { escapeHtml } from '../../../utils/html';
import { groupJobHistoryByMediaKind, jobHistoryMediaKind } from '../../../utils/job-history-media';
import { languageLabel } from '../../../utils/languages';
import type { PopupState } from '../../../utils/messages';
import { formatHistoryTimestamp, generationProgress } from '../../../utils/popup-progress';
import { formatDurationSeconds, formatJobTiming, publicJobTelemetry, stageTimeline } from '../../../utils/popup-saas-state';

export function renderJobHistory(state: PopupState, elements: { jobsList: HTMLElement; jobsError: HTMLElement }): void {
  if (state.jobHistoryError) {
    elements.jobsError.hidden = false;
    elements.jobsError.textContent = state.jobHistoryError;
  } else {
    elements.jobsError.hidden = true;
    elements.jobsError.textContent = '';
  }

  if (state.jobHistory.length === 0) {
    elements.jobsList.innerHTML = '<p class="muted empty-state">No backend jobs yet.</p>';

    return;
  }

  const groups = groupJobHistoryByMediaKind(state.jobHistory);

  elements.jobsList.innerHTML = [
    jobHistorySectionHtml('Videos', groups.videos, state),
    jobHistorySectionHtml('Shorts', groups.shorts, state),
  ].join('');
}

function jobHistorySectionHtml(title: string, jobs: PopupState['jobHistory'], state: PopupState): string {
  const content = jobs.length === 0
    ? `<p class="muted empty-state">No ${title.toLowerCase()} jobs yet.</p>`
    : jobs.map((job) => jobHistoryItemHtml(job, state)).join('');

  return `
    <section class="job-section" aria-label="${escapeHtml(title)} jobs">
      <div class="job-section-heading">
        <h3>${escapeHtml(title)}</h3>
        <span>${jobs.length}</span>
      </div>
      <div class="job-section-list">${content}</div>
    </section>
  `;
}

function jobHistoryItemHtml(job: PopupState['jobHistory'][number], state: PopupState): string {
  const telemetry = publicJobTelemetry(job);
  const progress = generationProgress(job);
  const mediaLabel = jobHistoryMediaKind(job) === 'short' ? 'Shorts' : 'Video';
  const meta = [
    languageRouteLabel(job),
    job.detectedSourceLanguage ? `Detected ${languageLabel(job.detectedSourceLanguage)}` : null,
    formatDurationSeconds(telemetry.videoDurationSeconds),
    formatJobTiming(job),
    formatHistoryTimestamp(job.completedAt ?? job.lastUpdatedAt ?? job.startedAt),
  ]
    .filter((value): value is string => typeof value === 'string' && value !== '')
    .map((value) => `<span>${escapeHtml(value)}</span>`)
    .join('');
  const controls = jobControls(job)
    .map((value) => `<span>${escapeHtml(value)}</span>`)
    .join('');
  const message = telemetry.errorMessage ?? (job.status === 'completed' ? 'Track ready' : progress.stageLabel);

  return `
    <article class="job-item">
      <header>
        <div>
          <span class="job-title">${escapeHtml(job.youtubeVideoId)}</span>
          <p class="job-id">Job ${escapeHtml(telemetry.publicJobId)}</p>
        </div>
        <span class="job-badge ${job.status}">${escapeHtml(job.status)}</span>
      </header>
      <div class="job-type-row"><span class="media-badge">${escapeHtml(mediaLabel)}</span></div>
      <div class="job-meta">${meta}</div>
      <div class="job-controls">${controls}</div>
      ${stageTimelineHtml(job)}
      <p class="job-message ${job.status === 'failed' ? 'error-copy' : ''}">${escapeHtml(message)}</p>
      <div class="job-actions">
        ${
          job.status === 'failed'
            ? `<button class="job-action-button" type="button" data-action="retry-job" data-video-id="${escapeHtml(
                job.youtubeVideoId,
              )}" data-video-url="${escapeHtml(job.youtubeUrl)}">Retry</button>`
            : ''
        }
        <button class="job-action-button" type="button" data-action="view-video" data-video-url="${escapeHtml(
          job.youtubeUrl,
        )}">Open video</button>
      </div>
    </article>
  `;
}

function stageTimelineHtml(job: PopupState['jobHistory'][number]): string {
  return `
    <ol class="stage-timeline" aria-label="Generation stage timeline">
      ${stageTimeline(job)
        .map((item) => `<li class="${item.state}" title="${escapeHtml(item.label)}"><span>${escapeHtml(item.label)}</span></li>`)
        .join('')}
    </ol>
  `;
}

function jobControls(job: PopupState['jobHistory'][number]): string[] {
  return [
    job.includeTranslation ? 'Translated cues' : 'Transcript cues',
    job.includeRomanization ? 'Romanization when available' : 'Romanization off',
    job.enrichmentMode === 'full' ? 'Full word cards' : 'On-click word cards',
  ];
}

function languageRouteLabel(job: PopupState['jobHistory'][number]): string {
  return `${languageLabel(job.sourceLanguage)} to ${languageLabel(job.targetLanguage)}`;
}

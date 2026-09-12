import { publicSubtitleErrorMessage, SubtitleApiError } from './api';
import type { JobResponse, SubtitleJobHistoryItem } from './contracts';
import type { SubtitleState } from './messages';
import type { YoutubePageInfo } from './youtube';

export type CompletedSubtitleJobResolver = (job: SubtitleJobHistoryItem) => Promise<JobResponse | null>;

export async function stateWithBackendProgress(
  localState: SubtitleState,
  pageStatus: YoutubePageInfo | undefined,
  jobs: SubtitleJobHistoryItem[],
  resolveCompletedJob?: CompletedSubtitleJobResolver,
): Promise<SubtitleState> {
  if (!pageStatus?.supported) {
    return localState;
  }

  if (localState.type === 'ready' || localState.type === 'error') {
    return localState;
  }

  if (localState.type === 'loading' && !localState.jobId) return localState;

  const activeJob = jobs.find(
    (candidate) => candidate.youtubeVideoId === pageStatus.videoId
      && (candidate.status === 'running' || candidate.status === 'queued'),
  );
  const job = localState.type === 'loading'
    ? jobs.find((candidate) => candidate.jobId === localState.jobId && candidate.youtubeVideoId === pageStatus.videoId)
    : activeJob
      ?? jobs.find((candidate) => candidate.youtubeVideoId === pageStatus.videoId && candidate.status === 'completed')
      ?? jobs.find((candidate) => candidate.youtubeVideoId === pageStatus.videoId && candidate.status === 'failed');

  if (!job) {
    return localState;
  }

  if (job.status === 'completed') {
    const completedJob = resolveCompletedJob ? await resolveCompletedJob(job) : null;

    if (completedJob?.status === 'completed' && completedJob.jobId === job.jobId
      && completedJob.track?.youtubeVideoId === pageStatus.videoId) {
      return {
        type: 'ready',
        track: completedJob.track,
      };
    }

    return localState;
  }

  if (job.status === 'failed') {
    return {
      type: 'error',
      jobId: job.jobId,
      youtubeVideoId: job.youtubeVideoId,
      message: publicSubtitleJobFailureMessage(job),
    };
  }

  if (job.status === 'cancelled') {
    return { type: 'no-track' };
  }

  return {
    type: 'loading',
    status: job.status,
    jobId: job.jobId,
    youtubeVideoId: job.youtubeVideoId,
    youtubeUrl: job.youtubeUrl,
    message: job.status === 'queued' ? 'Queued - waiting for a generation slot...' : loadingMessageForStage(job.stage),
    stage: job.stage,
    progressPercent: job.progressPercent,
    startedAt: job.startedAt,
    lastUpdatedAt: job.lastUpdatedAt,
    ...(localState.type === 'loading' && localState.partialTrack ? { partialTrack: localState.partialTrack } : {}),
  };
}

export function publicSubtitleJobFailureMessage(
  job: Pick<JobResponse | SubtitleJobHistoryItem, 'errorCode' | 'message'>,
): string {
  if (!job.errorCode || !job.message) {
    throw new Error('Failed subtitle job is missing error details.');
  }

  return publicSubtitleErrorMessage(new SubtitleApiError(job.errorCode, job.message, 500));
}

export function loadingMessageForStage(stage: SubtitleJobHistoryItem['stage']): string {
  switch (stage) {
    case 'acquiring-audio':
      return 'Acquiring audio...';

    case 'optimizing-audio':
      return 'Optimizing audio...';

    case 'transcribing':
      return 'Transcribing audio...';

    case 'tokenizing':
      return 'Analyzing subtitles...';

    case 'romanizing':
      return 'Adding romanization...';

    case 'translating':
      return 'Translating subtitles...';

    case 'finalizing':
      return 'Finalizing track...';

    case 'preparing':
    default:
      return 'Preparing request...';
  }
}

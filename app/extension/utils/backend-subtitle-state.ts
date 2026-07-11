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

  const activeJob = jobs.find(
    (candidate) => candidate.youtubeVideoId === pageStatus.videoId && candidate.status !== 'completed',
  );
  const job =
    activeJob ??
    jobs.find((candidate) => candidate.youtubeVideoId === pageStatus.videoId && candidate.status === 'completed');

  if (!job) {
    return localState;
  }

  if (job.status === 'completed') {
    const completedJob = resolveCompletedJob ? await resolveCompletedJob(job) : null;

    if (completedJob?.status === 'completed' && completedJob.track) {
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

  return {
    type: 'loading',
    jobId: job.jobId,
    youtubeVideoId: job.youtubeVideoId,
    youtubeUrl: job.youtubeUrl,
    message: job.status === 'queued' ? 'Queued - waiting for a generation slot...' : loadingMessageForStage(job.stage),
    stage: job.stage,
    progressPercent: job.progressPercent,
    startedAt: job.startedAt,
    lastUpdatedAt: job.lastUpdatedAt,
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
      return 'Tokenizing subtitles...';

    case 'romanizing':
      return 'Adding romanization...';

    case 'translating':
      return 'Translating subtitles...';

    case 'enriching':
      return 'Generating word cards...';

    case 'finalizing':
      return 'Finalizing track...';

    case 'preparing':
    default:
      return 'Preparing request...';
  }
}

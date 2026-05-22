import type { AccountSummary, SubtitleJobHistoryItem } from './contracts';
import { publicSubtitleErrorMessage, SubtitleApiError } from './api';
import type { AccountState } from './messages';
import { GENERATION_STAGES, stageLabel } from './popup-progress';

const LOCAL_BETA_MONTHLY_MINUTES = 60;

export interface PublicJobTelemetry {
  publicJobId: string;
  videoDurationSeconds?: number;
  errorMessage?: string;
}

export interface StageTimelineItem {
  stage: SubtitleJobHistoryItem['stage'];
  label: string;
  state: 'done' | 'current' | 'pending' | 'failed';
}

export function accountStateFromJobHistory(
  jobs: readonly SubtitleJobHistoryItem[],
  now: Date = new Date(),
): AccountState {
  const completedMinutes = jobs.reduce(
    (total, job) => total + (job.status === 'completed' ? billableMinutes(job) : 0),
    0,
  );
  const pendingMinutes = jobs.reduce(
    (total, job) => total + (job.status === 'running' ? billableMinutes(job) : 0),
    0,
  );

  return {
    status: 'anonymous',
    planName: 'Local beta',
    tierName: 'Base',
    tierSpeedLabel: 'Standard queue',
    monthlyMinuteLimit: LOCAL_BETA_MONTHLY_MINUTES,
    monthlyMinutesUsed: completedMinutes,
    monthlyMinutesPending: pendingMinutes,
    monthlyMinutesRemaining: Math.max(0, LOCAL_BETA_MONTHLY_MINUTES - completedMinutes - pendingMinutes),
    resetAt: nextMonthlyReset(now).toISOString(),
    upgradeAvailable: true,
  };
}

export function accountStateFromSummary(account: AccountSummary): AccountState {
  return {
    status: 'authenticated',
    id: account.id,
    email: account.email,
    name: account.name,
    emailVerified: account.emailVerified,
    planName: account.planName,
    tierName: account.tierName,
    tierSpeedLabel: account.tierSpeedLabel,
    monthlyMinuteLimit: account.monthlyMinuteLimit,
    monthlyMinutesUsed: account.monthlyMinutesUsed,
    monthlyMinutesPending: account.monthlyMinutesPending,
    monthlyMinutesRemaining: account.monthlyMinutesRemaining,
    resetAt: account.resetAt,
    upgradeAvailable: account.upgradeAvailable,
  };
}

export function publicJobTelemetry(job: SubtitleJobHistoryItem): PublicJobTelemetry {
  const telemetry: PublicJobTelemetry = {
    publicJobId: shortPublicId(job.jobId),
    errorMessage: failedJobPublicErrorMessage(job),
  };

  if (isPositiveInteger(job.videoDurationSeconds)) {
    telemetry.videoDurationSeconds = job.videoDurationSeconds;
  }

  return telemetry;
}

export function stageTimeline(job: Pick<SubtitleJobHistoryItem, 'stage' | 'status'>): StageTimelineItem[] {
  const currentIndex = GENERATION_STAGES.indexOf(job.stage);

  return GENERATION_STAGES.map((stage, index) => {
    let state: StageTimelineItem['state'] = 'pending';

    if (job.status === 'completed' || index < currentIndex) {
      state = 'done';
    } else if (index === currentIndex) {
      state = job.status === 'failed' ? 'failed' : 'current';
    }

    return {
      stage,
      label: stageLabel(stage),
      state,
    };
  });
}

export function formatDurationSeconds(seconds: number | undefined): string {
  if (!isPositiveInteger(seconds)) {
    return 'Duration pending';
  }

  const minutes = Math.floor(seconds / 60);
  const remainder = seconds % 60;

  if (minutes === 0) {
    return `${remainder}s`;
  }

  return remainder === 0 ? `${minutes}m` : `${minutes}m ${remainder}s`;
}

export function formatJobTiming(job: Pick<SubtitleJobHistoryItem, 'startedAt' | 'lastUpdatedAt' | 'completedAt' | 'status'>): string {
  const startedAt = Date.parse(job.startedAt);
  const endedAt = Date.parse(job.completedAt ?? job.lastUpdatedAt);

  if (Number.isNaN(startedAt) || Number.isNaN(endedAt) || endedAt < startedAt) {
    throw new Error('Job timing contains invalid timestamps.');
  }

  const label = job.status === 'running' ? 'elapsed' : job.status === 'failed' ? 'until failure' : 'total';

  return `${formatDurationSeconds(Math.max(1, Math.round((endedAt - startedAt) / 1000)))} ${label}`;
}

export function formatResetDate(value: string): string {
  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    throw new Error(`Invalid usage reset timestamp: ${value}`);
  }

  return date.toLocaleDateString(undefined, {
    month: 'short',
    day: 'numeric',
    timeZone: 'UTC',
  });
}

function failedJobPublicErrorMessage(job: SubtitleJobHistoryItem): string | undefined {
  if (job.status !== 'failed') {
    return undefined;
  }

  if (!job.errorCode || !job.message) {
    throw new Error('Failed job history item is missing public error fields.');
  }

  return publicSubtitleErrorMessage(new SubtitleApiError(job.errorCode, job.message, 500));
}

function billableMinutes(job: SubtitleJobHistoryItem): number {
  const { videoDurationSeconds: seconds } = job;

  return isPositiveInteger(seconds) ? Math.max(1, Math.ceil(seconds / 60)) : 0;
}

function nextMonthlyReset(now: Date): Date {
  return new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() + 1, 1, 0, 0, 0));
}

function shortPublicId(id: string): string {
  return id.length > 13 ? `${id.slice(0, 8)}...${id.slice(-4)}` : id;
}

function isPositiveInteger(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value) && value > 0;
}

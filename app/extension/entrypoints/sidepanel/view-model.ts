import type { AccountState, PopupState } from '../../utils/messages';
import { formatDurationSeconds } from '../../utils/popup-saas-state';

export function statusClass(subtitleStateType: PopupState['subtitleState']['type'], supported: boolean): string {
  if (subtitleStateType === 'error') {
    return 'error';
  }

  if (subtitleStateType === 'loading') {
    return 'loading';
  }

  return supported ? 'ok' : 'idle';
}

export function statusLabel(subtitleStateType: PopupState['subtitleState']['type'], supported: boolean): string {
  if (subtitleStateType === 'error') {
    return 'Generation failed';
  }

  if (subtitleStateType === 'loading') {
    return 'Generating subtitles';
  }

  return supported ? 'Ready to generate' : 'Unsupported page';
}

export function jobIdForState(subtitleState: PopupState['subtitleState']): string | null {
  switch (subtitleState.type) {
    case 'loading':
    case 'error':
      return subtitleState.jobId ? shortDisplayId(subtitleState.jobId) : null;

    case 'ready':
      return shortDisplayId(subtitleState.track.jobId);

    case 'no-track':
      return null;
  }
}

export function videoDurationLabel(state: PopupState): string {
  const duration = videoDurationForState(state);

  return typeof duration === 'number' ? formatDurationSeconds(duration) : 'No supported video';
}

export function videoDurationForState(state: PopupState): number | undefined {
  if (typeof state.pageVideoDurationSeconds === 'number') {
    return state.pageVideoDurationSeconds;
  }

  const pageVideoId = state.pageStatus?.supported ? state.pageStatus.videoId : null;
  const matchingJob = pageVideoId
    ? state.jobHistory.find((job) => job.youtubeVideoId === pageVideoId && typeof job.videoDurationSeconds === 'number')
    : undefined;

  return matchingJob?.videoDurationSeconds;
}

export function generateButtonLabel(
  accountState: AccountState,
  subtitleStateType: PopupState['subtitleState']['type'],
): string {
  if (subtitleStateType === 'loading') {
    return 'Generating...';
  }

  if (accountState.status !== 'authenticated') {
    return 'Sign in to generate';
  }

  return 'Generate subtitles';
}

export function shortDisplayId(id: string): string {
  return id.length > 13 ? `${id.slice(0, 8)}...${id.slice(-4)}` : id;
}

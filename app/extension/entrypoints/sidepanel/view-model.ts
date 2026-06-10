import type { AccountState, PanelState } from '../../utils/messages';
import { formatDurationSeconds } from '../../utils/account-state';

export function statusClass(subtitleStateType: PanelState['subtitleState']['type'], supported: boolean): string {
  if (subtitleStateType === 'error') {
    return 'error';
  }

  if (subtitleStateType === 'loading') {
    return 'loading';
  }

  return supported ? 'ok' : 'idle';
}

export function statusLabel(subtitleStateType: PanelState['subtitleState']['type'], supported: boolean): string {
  if (subtitleStateType === 'error') {
    return 'Generation failed';
  }

  if (subtitleStateType === 'loading') {
    return 'Generating subtitles';
  }

  return supported ? 'Ready to generate' : 'Unsupported page';
}

export function videoDurationLabel(state: PanelState): string {
  const duration = videoDurationForState(state);

  return typeof duration === 'number' ? formatDurationSeconds(duration) : 'No supported video';
}

export function videoDurationForState(state: PanelState): number | undefined {
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
  subtitleStateType: PanelState['subtitleState']['type'],
): string {
  if (subtitleStateType === 'loading') {
    return 'Generating...';
  }

  if (accountState.status !== 'authenticated') {
    return 'Sign in to generate';
  }

  return 'Generate subtitles';
}

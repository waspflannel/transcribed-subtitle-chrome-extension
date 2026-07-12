import type { AccountState, PanelState } from '../../utils/messages';
import { formatDurationSeconds } from '../../utils/account-state';

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

/** Human title for the now-playing header: tab title minus YouTube chrome, falling back to the video id. */
export function nowPlayingTitleLabel(state: PanelState): string {
  if (state.pageStatus?.supported !== true) {
    return 'Open a YouTube video';
  }

  const cleaned = (state.pageTitle ?? '')
    .replace(/\s*-\s*YouTube\s*$/i, '')
    .replace(/^\(\d+\)\s*/, '')
    .trim();

  return cleaned !== '' ? cleaned : state.pageStatus.videoId;
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

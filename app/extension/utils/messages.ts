import type { ExtensionSettings } from './settings-model';
import type { YoutubePageInfo } from './youtube';
import type { SubtitleJobHistoryItem, TrackResponse } from './contracts';

export interface PageSnapshot {
  videoDurationSeconds?: number;
}

export interface AccountState {
  status: 'anonymous' | 'authenticated';
  id?: string;
  email?: string;
  name?: string;
  emailVerified?: boolean;
  planName: string;
  tierName: string;
  tierSpeedLabel: string;
  monthlyMinuteLimit: number;
  monthlyMinutesUsed: number;
  monthlyMinutesPending: number;
  monthlyMinutesRemaining: number;
  resetAt: string;
  upgradeAvailable: boolean;
}

export type SubtitleState =
  | {
      type: 'no-track';
    }
  | {
      type: 'loading';
      jobId?: string;
      youtubeVideoId: string;
      youtubeUrl?: string;
      message: string;
      stage: SubtitleJobHistoryItem['stage'];
      progressPercent: number;
      startedAt?: string;
      lastUpdatedAt?: string;
    }
  | {
      type: 'ready';
      track: TrackResponse;
    }
  | {
      type: 'error';
      jobId?: string;
      youtubeVideoId?: string;
      message: string;
    };

export const DEFAULT_SUBTITLE_STATE: SubtitleState = { type: 'no-track' };

export interface PopupState {
  installId: string;
  settings: ExtensionSettings;
  activeTabId?: number;
  pageStatus?: YoutubePageInfo;
  pageVideoDurationSeconds?: number;
  accountState: AccountState;
  subtitleState: SubtitleState;
  jobHistory: SubtitleJobHistoryItem[];
  jobHistoryError?: string;
}

export type RuntimeMessage =
  | {
      type: 'content.getState';
    }
  | {
      type: 'popup.getState';
      syncBackend?: boolean;
    }
  | {
      type: 'popup.updateSettings';
      patch: Partial<ExtensionSettings>;
    }
  | {
      type: 'content.updateSettings';
      patch: Partial<ExtensionSettings>;
    }
  | {
      type: 'popup.generateSubtitles';
    }
  | {
      type: 'popup.login';
      email: string;
      password: string;
    }
  | {
      type: 'popup.logout';
    }
  | {
      type: 'content.enrichLearningToken';
      youtubeVideoId: string;
      trackId: string;
      cueId: string;
      tokenIndex: number;
    }
  | {
      type: 'background.getPageSnapshot';
    }
  | {
      type: 'popup.clearLocalState';
    }
  | {
      type: 'background.settingsChanged';
      settings: ExtensionSettings;
    }
  | {
      type: 'background.subtitleStateChanged';
      subtitleState: SubtitleState;
    }
  | { type: 'content.activeCueChanged'; cueId: string | null; youtubeVideoId: string }
  | { type: 'background.activeCueChanged'; cueId: string | null; youtubeVideoId: string }
  | { type: 'popup.seekToCue'; cueId: string; mode: 'jump' | 'replay' }
  | { type: 'background.seekToCue'; cueId: string; mode: 'jump' | 'replay' }
  | { type: 'content.focusPanelTranscript' }
  | { type: 'background.focusTranscript' };

export function isRuntimeMessage(value: unknown): value is RuntimeMessage {
  if (!isRecord(value) || typeof value.type !== 'string') {
    return false;
  }

  switch (value.type) {
    case 'content.getState':
    case 'popup.generateSubtitles':
    case 'popup.logout':
    case 'popup.clearLocalState':
    case 'background.getPageSnapshot':
      return true;

    case 'popup.login':
      return hasString(value, 'email') && hasString(value, 'password');

    case 'popup.getState':
      return optionalBoolean(value, 'syncBackend');

    case 'popup.updateSettings':
    case 'content.updateSettings':
      return isRecord(value.patch);

    case 'content.enrichLearningToken':
      return hasString(value, 'youtubeVideoId')
        && hasString(value, 'trackId')
        && hasString(value, 'cueId')
        && isNonNegativeInteger(value.tokenIndex);

    case 'background.settingsChanged':
      return isRecord(value.settings);

    case 'background.subtitleStateChanged':
      return isSubtitleStateValue(value.subtitleState);

    case 'content.focusPanelTranscript':
    case 'background.focusTranscript':
      return true;

    case 'content.activeCueChanged':
    case 'background.activeCueChanged':
      return (value.cueId === null || hasString(value, 'cueId')) && hasString(value, 'youtubeVideoId');

    case 'popup.seekToCue':
    case 'background.seekToCue':
      return hasString(value, 'cueId') && (value.mode === 'jump' || value.mode === 'replay');

    default:
      return false;
  }
}

function isSubtitleStateValue(value: unknown): value is SubtitleState {
  if (!isRecord(value) || typeof value.type !== 'string') {
    return false;
  }

  switch (value.type) {
    case 'no-track':
      return true;

    case 'loading':
      return hasString(value, 'youtubeVideoId')
        && hasString(value, 'message')
        && isSubtitleStage(value.stage)
        && isProgressPercent(value.progressPercent)
        && optionalString(value, 'jobId')
        && optionalString(value, 'youtubeUrl')
        && optionalString(value, 'startedAt')
        && optionalString(value, 'lastUpdatedAt');

    case 'ready':
      return isRecord(value.track);

    case 'error':
      return hasString(value, 'message') && optionalString(value, 'jobId') && optionalString(value, 'youtubeVideoId');

    default:
      return false;
  }
}

function isSubtitleStage(value: unknown): value is SubtitleJobHistoryItem['stage'] {
  return value === 'preparing'
    || value === 'acquiring-audio'
    || value === 'optimizing-audio'
    || value === 'transcribing'
    || value === 'tokenizing'
    || value === 'romanizing'
    || value === 'translating'
    || value === 'enriching'
    || value === 'finalizing';
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function hasString(value: Record<string, unknown>, key: string): boolean {
  return typeof value[key] === 'string' && value[key] !== '';
}

function optionalString(value: Record<string, unknown>, key: string): boolean {
  return !(key in value) || typeof value[key] === 'string';
}

function optionalBoolean(value: Record<string, unknown>, key: string): boolean {
  return !(key in value) || typeof value[key] === 'boolean';
}

function isNonNegativeInteger(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0;
}

function isProgressPercent(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0 && value <= 100;
}

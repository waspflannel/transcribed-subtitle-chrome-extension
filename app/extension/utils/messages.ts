import type { ExtensionSettings } from './settings-model';
import type { YoutubePageInfo } from './youtube';
import type { SubtitleJobHistoryItem, TrackResponse } from './contracts';

export interface PageSnapshot {
  videoDurationSeconds?: number;
}

export interface AnonymousAccountState {
  status: 'anonymous';
}

export interface AuthenticatedAccountState {
  status: 'authenticated';
  id: string;
  email: string;
  name: string;
  emailVerified: boolean;
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

export type AccountState = AnonymousAccountState | AuthenticatedAccountState;

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

export interface PanelState {
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

export type BackgroundRequest =
  | {
      type: 'content.getState';
    }
  | {
      type: 'panel.getState';
      syncBackend?: boolean;
    }
  | {
      type: 'panel.updateSettings';
      patch: Partial<ExtensionSettings>;
    }
  | {
      type: 'content.updateSettings';
      patch: Partial<ExtensionSettings>;
    }
  | {
      type: 'panel.generateSubtitles';
    }
  | {
      type: 'panel.login';
      email: string;
      password: string;
    }
  | {
      type: 'panel.logout';
    }
  | {
      type: 'content.enrichLearningToken';
      youtubeVideoId: string;
      trackId: string;
      cueId: string;
      tokenIndex: number;
    }
  | {
      type: 'panel.clearLocalState';
    }
  | { type: 'content.activeCueChanged'; cueId: string | null; youtubeVideoId: string }
  | { type: 'panel.seekToCue'; youtubeVideoId: string; cueId: string; mode: 'jump' | 'replay' }
  | { type: 'content.focusPanelTranscript' };

export type ContentRequest =
  | {
      type: 'background.getPageSnapshot';
    }
  | {
      type: 'background.settingsChanged';
      settings: ExtensionSettings;
    }
  | {
      type: 'background.subtitleStateChanged';
      subtitleState: SubtitleState;
    }
  | { type: 'background.seekToCue'; cueId: string; mode: 'jump' | 'replay' };

export type PanelNotice =
  | { type: 'background.activeCueChanged'; cueId: string | null; youtubeVideoId: string }
  | { type: 'background.focusTranscript' };

export type PanelRequest = Extract<BackgroundRequest, { type: `panel.${string}` }>;
export type RuntimeMessage = BackgroundRequest | ContentRequest | PanelNotice;

export function isRuntimeMessage(value: unknown): value is RuntimeMessage {
  if (!isRecord(value) || typeof value.type !== 'string') {
    return false;
  }

  switch (value.type) {
    case 'content.getState':
    case 'panel.generateSubtitles':
    case 'panel.logout':
    case 'panel.clearLocalState':
    case 'background.getPageSnapshot':
      return true;

    case 'panel.login':
      return hasString(value, 'email') && hasString(value, 'password');

    case 'panel.getState':
      return optionalBoolean(value, 'syncBackend');

    case 'panel.updateSettings':
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

    case 'panel.seekToCue':
    case 'background.seekToCue':
      return hasString(value, 'cueId')
        && (value.type !== 'panel.seekToCue' || hasString(value, 'youtubeVideoId'))
        && (value.mode === 'jump' || value.mode === 'replay');

    default:
      return false;
  }
}

export function isBackgroundRequest(message: RuntimeMessage): message is BackgroundRequest {
  switch (message.type) {
    case 'content.getState':
    case 'content.updateSettings':
    case 'content.enrichLearningToken':
    case 'content.activeCueChanged':
    case 'content.focusPanelTranscript':
    case 'panel.getState':
    case 'panel.updateSettings':
    case 'panel.generateSubtitles':
    case 'panel.login':
    case 'panel.logout':
    case 'panel.clearLocalState':
    case 'panel.seekToCue':
      return true;

    case 'background.getPageSnapshot':
    case 'background.settingsChanged':
    case 'background.subtitleStateChanged':
    case 'background.activeCueChanged':
    case 'background.seekToCue':
    case 'background.focusTranscript':
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

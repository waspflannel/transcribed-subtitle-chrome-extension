import type { ExtensionSettings } from './settings-model';
import type { YoutubePageInfo } from './youtube';
import type { LyricsCorrectionStatus, PartialSubtitleCue, SubtitleJobHistoryItem, TrackResponse } from './contracts';

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

/**
 * Cues already available for a still-running job, composed from the
 * partial-track response plus the job's effective source language. The
 * content script binds these to the video so subtitles render while the
 * pipeline is still translating and romanizing.
 */
export interface PartialSubtitleTrack {
  jobId: string;
  youtubeVideoId: string;
  sourceLanguage: string;
  revision: number;
  cues: PartialSubtitleCue[];
}

export type SubtitleState =
  | {
      type: 'no-track';
    }
  | {
      type: 'loading';
      status?: 'queued' | 'running';
      jobId?: string;
      youtubeVideoId: string;
      youtubeUrl?: string;
      message: string;
      stage: SubtitleJobHistoryItem['stage'];
      progressPercent: number;
      startedAt?: string;
      lastUpdatedAt?: string;
      partialTrack?: PartialSubtitleTrack;
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
  pageTitle?: string;
  pageVideoDurationSeconds?: number;
  accountState: AccountState;
  subtitleState: SubtitleState;
  jobHistory: SubtitleJobHistoryItem[];
  jobHistoryError?: string;
  lyricsCorrection?: LyricsCorrectionStatus | null;
  lyricsCorrectionSyncError?: string;
}

export type BackgroundRequest =
  | {
      type: 'content.getState';
    }
  | {
      type: 'panel.getState';
      syncBackend?: boolean;
      windowId?: number;
    }
  | {
      type: 'panel.updateSettings';
      patch: Partial<ExtensionSettings>;
      windowId?: number;
    }
  | {
      type: 'content.updateSettings';
      patch: Partial<ExtensionSettings>;
    }
  | {
      type: 'panel.generateSubtitles';
      windowId?: number;
    }
  | {
      type: 'panel.submitLyricsCorrection';
      jobId: string;
      trackId: string;
      youtubeVideoId: string;
      lyrics: string;
      allowPartial?: boolean;
      windowId?: number;
    }
  | {
      type: 'panel.cancelLyricsCorrection';
      jobId: string;
      trackId: string;
      attemptId: string;
      youtubeVideoId: string;
      windowId?: number;
    }
  | {
      type: 'panel.quickFixToken';
      jobId: string;
      trackId: string;
      youtubeVideoId: string;
      cueId: string;
      tokenIndex: number;
      text: string;
      windowId?: number;
    }
  | {
      type: 'panel.login';
      email: string;
      password: string;
    }
  | {
      type: 'panel.logout';
      windowId?: number;
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
      windowId?: number;
    }
  | { type: 'content.activeCueChanged'; cueId: string | null; youtubeVideoId: string; trackId: string | null }
  | { type: 'panel.getActiveCue'; tabId: number; youtubeVideoId: string; trackId: string; windowId?: number }
  | { type: 'panel.seekToCue'; youtubeVideoId: string; trackId: string; cueId: string; mode: 'jump' | 'replay'; tabId?: number; windowId?: number }
  | { type: 'content.focusPanelTranscript' };

export type ContentRequest =
  | { type: 'background.getActiveCue'; youtubeVideoId: string; trackId: string }
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
  | { type: 'background.seekToCue'; youtubeVideoId: string; trackId: string; cueId: string; mode: 'jump' | 'replay' };

export type PanelNotice =
  | { type: 'background.activeCueChanged'; cueId: string | null; youtubeVideoId: string; trackId: string; tabId: number; windowId?: number }
  | { type: 'background.focusTranscript'; windowId?: number };

export type PanelRequest = Extract<BackgroundRequest, { type: `panel.${string}` }>;
export type RuntimeMessage = BackgroundRequest | ContentRequest | PanelNotice;

export function isRuntimeMessage(value: unknown): value is RuntimeMessage {
  if (!isRecord(value) || typeof value.type !== 'string') {
    return false;
  }

  switch (value.type) {
    case 'panel.getActiveCue':
      return isNonNegativeInteger(value.tabId) && hasString(value, 'youtubeVideoId') && hasString(value, 'trackId')
        && optionalNumber(value, 'windowId');
    case 'background.getActiveCue':
      return hasString(value, 'youtubeVideoId') && hasString(value, 'trackId');
    case 'content.getState':
    case 'panel.generateSubtitles':
    case 'panel.logout':
    case 'panel.clearLocalState':
    case 'background.getPageSnapshot':
      return optionalNumber(value, 'windowId');

    case 'panel.submitLyricsCorrection':
      return hasString(value, 'jobId')
        && hasString(value, 'trackId')
        && hasString(value, 'youtubeVideoId')
        && hasString(value, 'lyrics')
        && optionalBoolean(value, 'allowPartial')
        && optionalNumber(value, 'windowId');

    case 'panel.cancelLyricsCorrection':
      return hasString(value, 'jobId')
        && hasString(value, 'trackId')
        && hasString(value, 'attemptId')
        && hasString(value, 'youtubeVideoId')
        && optionalNumber(value, 'windowId');

    case 'panel.quickFixToken':
      return hasString(value, 'jobId')
        && hasString(value, 'trackId')
        && hasString(value, 'youtubeVideoId')
        && hasString(value, 'cueId')
        && isNonNegativeInteger(value.tokenIndex)
        && hasString(value, 'text')
        && optionalNumber(value, 'windowId');

    case 'panel.login':
      return hasString(value, 'email') && hasString(value, 'password');

    case 'panel.getState':
      return optionalBoolean(value, 'syncBackend') && optionalNumber(value, 'windowId');

    case 'panel.updateSettings':
    case 'content.updateSettings':
      return isRecord(value.patch) && optionalNumber(value, 'windowId');

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
      return (value.cueId === null || hasString(value, 'cueId')) && hasString(value, 'youtubeVideoId')
        && (!('trackId' in value) || value.trackId === null || hasString(value, 'trackId'));
    case 'background.activeCueChanged':
      return (value.cueId === null || hasString(value, 'cueId')) && hasString(value, 'youtubeVideoId')
        && (!('trackId' in value) || hasString(value, 'trackId'))
        && (!('tabId' in value) || isNonNegativeInteger(value.tabId)) && optionalNumber(value, 'windowId');

    case 'panel.seekToCue':
    case 'background.seekToCue':
      return hasString(value, 'cueId')
        && (value.type === 'background.seekToCue'
          || (hasString(value, 'youtubeVideoId')
            && (hasString(value, 'trackId') || !('tabId' in value))))
        && (value.mode === 'jump' || value.mode === 'replay')
        && (value.type !== 'panel.seekToCue' || optionalNumber(value, 'tabId'))
        && optionalNumber(value, 'windowId');

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
    case 'panel.submitLyricsCorrection':
    case 'panel.cancelLyricsCorrection':
    case 'panel.quickFixToken':
    case 'panel.login':
    case 'panel.logout':
    case 'panel.clearLocalState':
    case 'panel.seekToCue':
      return true;
    case 'panel.getActiveCue':
      return true;
    case 'background.getActiveCue':
      return false;

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
        && (!('status' in value) || value.status === 'queued' || value.status === 'running')
        && optionalString(value, 'jobId')
        && optionalString(value, 'youtubeUrl')
        && optionalString(value, 'startedAt')
        && optionalString(value, 'lastUpdatedAt')
        && (!('partialTrack' in value) || value.partialTrack === undefined || isPartialSubtitleTrack(value.partialTrack));

    case 'ready':
      return isRecord(value.track);

    case 'error':
      return hasString(value, 'message') && optionalString(value, 'jobId') && optionalString(value, 'youtubeVideoId');

    default:
      return false;
  }
}

function isPartialSubtitleTrack(value: unknown): value is PartialSubtitleTrack {
  return isRecord(value)
    && hasString(value, 'jobId')
    && hasString(value, 'youtubeVideoId')
    && hasString(value, 'sourceLanguage')
    && isNonNegativeInteger(value.revision)
    && Array.isArray(value.cues);
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

function optionalNumber(value: Record<string, unknown>, key: string): boolean {
  return !(key in value) || typeof value[key] === 'number';
}

function isNonNegativeInteger(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0;
}

function isProgressPercent(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0 && value <= 100;
}

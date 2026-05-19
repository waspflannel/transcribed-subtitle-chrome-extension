import type { ExtensionSettings } from './settings-model';
import type { YoutubePageInfo } from './youtube';
import type { SubtitleJobHistoryItem, TrackResponse } from './contracts';

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
      type: 'popup.generateSubtitles';
    }
  | {
      type: 'content.enrichLearningToken';
      youtubeVideoId: string;
      trackId: string;
      cueId: string;
      tokenIndex: number;
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
    };

export function isRuntimeMessage(value: unknown): value is RuntimeMessage {
  if (typeof value !== 'object' || value === null || !('type' in value)) {
    return false;
  }

  switch ((value as { type: unknown }).type) {
    case 'content.getState':
    case 'popup.getState':
    case 'popup.updateSettings':
    case 'popup.generateSubtitles':
    case 'content.enrichLearningToken':
    case 'popup.clearLocalState':
    case 'background.settingsChanged':
    case 'background.subtitleStateChanged':
      return true;

    default:
      return false;
  }
}

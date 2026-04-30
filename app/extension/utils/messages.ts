import type { ExtensionSettings } from './settings-model';
import type { YoutubePageInfo } from './youtube';
import type { JobResponse, TrackResponse } from './contracts';

export type SubtitleState =
  | {
      type: 'no-track';
    }
  | {
      type: 'processing';
      job: JobResponse;
    }
  | {
      type: 'ready';
      track: TrackResponse;
    }
  | {
      type: 'error';
      message: string;
      job?: JobResponse;
    };

export const DEFAULT_SUBTITLE_STATE: SubtitleState = { type: 'no-track' };

export interface ContentPageStatus {
  page: YoutubePageInfo;
  videoElementFound: boolean;
  videoDurationSeconds?: number;
  videoCurrentTimeSeconds?: number;
  updatedAt: number;
}

export interface PopupState {
  installId: string;
  settings: ExtensionSettings;
  activeTabId?: number;
  pageStatus?: ContentPageStatus;
  subtitleState: SubtitleState;
}

export type RuntimeMessage =
  | {
      type: 'content.statusChanged';
      status: ContentPageStatus;
    }
  | {
      type: 'content.getState';
    }
  | {
      type: 'popup.getState';
    }
  | {
      type: 'popup.updateSettings';
      patch: Partial<ExtensionSettings>;
    }
  | {
      type: 'popup.generateSubtitles';
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
  return (
    typeof value === 'object' &&
    value !== null &&
    'type' in value &&
    typeof (value as { type?: unknown }).type === 'string'
  );
}

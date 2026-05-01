import type { ExtensionSettings } from './settings-model';
import type { YoutubePageInfo } from './youtube';
import type { TrackResponse } from './contracts';

export type SubtitleState =
  | {
      type: 'no-track';
    }
  | {
      type: 'ready';
      track: TrackResponse;
    }
  | {
      type: 'error';
      message: string;
    };

export const DEFAULT_SUBTITLE_STATE: SubtitleState = { type: 'no-track' };

export interface PopupState {
  installId: string;
  settings: ExtensionSettings;
  activeTabId?: number;
  pageStatus?: YoutubePageInfo;
  subtitleState: SubtitleState;
}

export type RuntimeMessage =
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

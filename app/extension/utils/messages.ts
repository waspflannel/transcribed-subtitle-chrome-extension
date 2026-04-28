import type { ExtensionSettings } from './settings-model';
import type { YoutubePageInfo } from './youtube';

export type OverlayMode = 'no-track' | 'processing' | 'ready' | 'error';

export const DEFAULT_OVERLAY_MODE: OverlayMode = 'no-track';

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
  overlayMode: OverlayMode;
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
      type: 'popup.setOverlayMode';
      mode: OverlayMode;
    }
  | {
      type: 'background.settingsChanged';
      settings: ExtensionSettings;
    }
  | {
      type: 'background.overlayModeChanged';
      mode: OverlayMode;
    };

export function isRuntimeMessage(value: unknown): value is RuntimeMessage {
  return (
    typeof value === 'object' &&
    value !== null &&
    'type' in value &&
    typeof (value as { type?: unknown }).type === 'string'
  );
}

export function isOverlayMode(value: unknown): value is OverlayMode {
  return value === 'no-track' || value === 'processing' || value === 'ready' || value === 'error';
}

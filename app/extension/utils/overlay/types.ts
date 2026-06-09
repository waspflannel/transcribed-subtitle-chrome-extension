import type { LearningToken, SubtitleCue } from '../contracts';
import type { SubtitleState } from '../messages';
import type { ExtensionSettings } from '../settings-model';
import type { YoutubePageInfo } from '../youtube';

export interface OverlayRenderState {
  page: YoutubePageInfo;
  subtitleState: SubtitleState;
  settings: ExtensionSettings;
  activeCue?: SubtitleCue | null;
  pendingTokenKeys?: ReadonlySet<string>;
  failedTokenKeys?: ReadonlySet<string>;
}

export interface OverlayInteractionState {
  pinnedTokenIndex: number | null;
  actionStatus?: OverlayStatus | null;
  copyStatus?: 'copied' | 'failed' | null;
  pendingTokenKeys?: ReadonlySet<string>;
  failedTokenKeys?: ReadonlySet<string>;
}

export const EMPTY_INTERACTION: OverlayInteractionState = {
  pinnedTokenIndex: null,
};

export interface OverlayStatus {
  message: string;
  tone: 'info' | 'success' | 'error';
}

export type OverlayTokenClickHandler = (cue: SubtitleCue, token: LearningToken) => void;

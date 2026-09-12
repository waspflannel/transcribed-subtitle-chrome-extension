import type { PartialSubtitleCue, SubtitleCue } from '../contracts';
import type { SubtitleState } from '../messages';
import type { ExtensionSettings } from '../settings-model';
import type { YoutubePageInfo } from '../youtube';

export interface OverlayRenderState {
  page: YoutubePageInfo;
  subtitleState: SubtitleState;
  settings: ExtensionSettings;
  activeCue?: SubtitleCue | null;
  /** Active cue from a partial track while the job is still running. */
  activePartialCue?: PartialSubtitleCue | null;
  pendingTokenKeys?: ReadonlySet<string>;
  failedTokenKeys?: ReadonlySet<string>;
  bindingError?: string | null;
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

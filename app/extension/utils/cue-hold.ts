import type { SubtitleCue } from './contracts';

export interface CueHoldView {
  setTimeout(fn: () => void, ms: number): number;
  clearTimeout(id: number): void;
}

export interface CueHoldOptions {
  holdMs: number;
  view: CueHoldView;
  onExpire: () => void;
}

/**
 * Keeps the previous cue rendered across short inter-cue silences so the rail
 * does not blank and remount between adjacent cues. The hold is started only
 * while the video is playing; seeks, track teardown, and the next cue arriving
 * cancel it immediately.
 */
export class CueHoldController {
  private timeout: number | null = null;

  constructor(private readonly options: CueHoldOptions) {}

  /**
   * Returns the cue that should render after observing `incoming` (null when
   * the source track reports a gap). When a gap opens mid-playback, the prior
   * cue is held until the hold timeout fires or the next cue arrives.
   */
  select(incoming: SubtitleCue | null, isPlaying: boolean, current: SubtitleCue | null): SubtitleCue | null {
    if (incoming !== null) {
      this.cancel();
      return incoming;
    }

    if (current !== null && isPlaying) {
      this.scheduleExpiry();
      return current;
    }

    this.cancel();
    return null;
  }

  /** Immediately drop any active hold (seek, video change, teardown). */
  clear(): void {
    this.cancel();
  }

  private scheduleExpiry(): void {
    if (this.timeout !== null) {
      return;
    }
    this.timeout = this.options.view.setTimeout(() => {
      this.timeout = null;
      this.options.onExpire();
    }, this.options.holdMs);
  }

  private cancel(): void {
    if (this.timeout === null) {
      return;
    }
    this.options.view.clearTimeout(this.timeout);
    this.timeout = null;
  }
}

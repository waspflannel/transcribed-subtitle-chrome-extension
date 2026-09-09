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
  private paused = false;
  private holding = false;

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

    if (current !== null && (isPlaying || this.paused)) {
      if (!this.paused) {
        this.scheduleExpiry();
      }
      return current;
    }

    this.cancel();
    return null;
  }

  /** Immediately drop any active hold (seek, video change, teardown). */
  clear(): void {
    this.cancel();
    this.paused = false;
  }

  /** Suspend expiry while study owns a playback pause; the held cue remains visible. */
  pause(): void {
    this.paused = true;
    if (this.timeout === null) return;

    this.options.view.clearTimeout(this.timeout);
    this.timeout = null;
  }

  /** Resume expiry after study releases its pause. */
  resume(current: SubtitleCue | null, isPlaying: boolean): void {
    this.paused = false;

    if (current === null) {
      this.holding = false;
    } else if (this.holding && isPlaying) {
      this.scheduleExpiry();
    }
  }

  private scheduleExpiry(): void {
    if (this.timeout !== null) {
      return;
    }
    this.holding = true;
    this.timeout = this.options.view.setTimeout(() => {
      this.timeout = null;
      this.holding = false;
      this.options.onExpire();
    }, this.options.holdMs);
  }

  private cancel(): void {
    if (this.timeout !== null) {
      this.options.view.clearTimeout(this.timeout);
      this.timeout = null;
    }
    this.holding = false;
  }
}

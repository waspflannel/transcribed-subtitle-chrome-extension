import { describe, expect, it } from 'vitest';

import { CueHoldController } from '../utils/cue-hold';
import type { SubtitleCue } from '../utils/contracts';

describe('CueHoldController', () => {
  it('returns the incoming cue and cancels any active hold when a cue arrives', () => {
    const timers = fakeTimers();
    const controller = new CueHoldController({ holdMs: 1000, view: timers.view, onExpire: () => {} });
    const held = stubCue('cue-1');
    const next = stubCue('cue-2');

    expect(controller.select(null, true, held)).toBe(held);
    expect(controller.select(next, true, held)).toBe(next);
    timers.assertNoPending();
  });

  it('holds the prior cue across a gap while the video is playing, then expires', () => {
    const timers = fakeTimers();
    const expired: string[] = [];
    const controller = new CueHoldController({
      holdMs: 1800,
      view: timers.view,
      onExpire: () => expired.push('blank'),
    });
    const held = stubCue('cue-1');

    expect(controller.select(null, true, held)).toBe(held);
    expect(controller.select(null, true, held)).toBe(held);
    expect(expired).toEqual([]);

    timers.runPending(1800);

    expect(expired).toEqual(['blank']);
    expect(timers.pending()).toBe(0);
  });

  it('does not start a hold when the video is paused or no prior cue exists', () => {
    const timers = fakeTimers();
    const controller = new CueHoldController({ holdMs: 1000, view: timers.view, onExpire: () => {} });
    const held = stubCue('cue-1');

    expect(controller.select(null, false, held)).toBeNull();
    expect(controller.select(null, true, null)).toBeNull();
    expect(timers.pending()).toBe(0);
  });

  it('clear() cancels an active hold immediately (teardown / next cue path)', () => {
    const timers = fakeTimers();
    const controller = new CueHoldController({ holdMs: 1000, view: timers.view, onExpire: () => {} });
    const held = stubCue('cue-1');

    controller.select(null, true, held);
    controller.clear();

    expect(timers.pending()).toBe(0);
  });
});

function fakeTimers() {
  const queue: Array<{ id: number; fn: () => void; at: number }> = [];
  let nextId = 1;
  let now = 0;
  const view = {
    setTimeout(fn: () => void, ms: number): number {
      const id = nextId += 1;
      queue.push({ id, fn, at: now + ms });
      return id;
    },
    clearTimeout(id: number): void {
      const index = queue.findIndex((entry) => entry.id === id);
      if (index >= 0) {
        queue.splice(index, 1);
      }
    },
  };
  return {
    view,
    pending: () => queue.length,
    runPending(ms: number): void {
      now += ms;
      const due = queue.filter((entry) => entry.at <= now);
      for (const entry of due) {
        const index = queue.indexOf(entry);
        if (index >= 0) {
          queue.splice(index, 1);
        }
        entry.fn();
      }
    },
    assertNoPending: () => {
      if (queue.length > 0) {
        throw new Error(`Expected no pending timers, found ${queue.length}.`);
      }
    },
  };
}

function stubCue(cueId: string): SubtitleCue {
  return {
    cueId,
    index: 0,
    startMs: 0,
    endMs: 1000,
    sourceText: 'source',
    translatedText: 'translation',
    tokens: [{ index: 0, text: 'a', normalizedText: 'a' }],
  };
}

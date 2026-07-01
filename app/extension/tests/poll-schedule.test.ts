import { describe, expect, it } from 'vitest';

import { ACTIVE_POLL_INTERVAL_MS, IDLE_POLL_INTERVAL_MS, pollIntervalMs, shouldPollNow } from '../utils/poll-schedule';

describe('shouldPollNow', () => {
  it('returns true when the panel is visible', () => {
    expect(shouldPollNow({ visibilityState: 'visible' })).toBe(true);
  });

  it('returns false when the panel is hidden', () => {
    expect(shouldPollNow({ visibilityState: 'hidden' })).toBe(false);
  });
});

describe('pollIntervalMs', () => {
  it('uses the active interval when a job is in-flight', () => {
    expect(pollIntervalMs(true)).toBe(ACTIVE_POLL_INTERVAL_MS);
  });

  it('uses the idle interval when no job is in-flight', () => {
    expect(pollIntervalMs(false)).toBe(IDLE_POLL_INTERVAL_MS);
  });

  it('idle interval is longer than the active interval', () => {
    expect(IDLE_POLL_INTERVAL_MS).toBeGreaterThan(ACTIVE_POLL_INTERVAL_MS);
  });
});

export interface PollDecisionInputs {
  visibilityState: 'visible' | 'hidden';
}

export const ACTIVE_POLL_INTERVAL_MS = 10_000;
export const IDLE_POLL_INTERVAL_MS = 30_000;

export function shouldPollNow(inputs: PollDecisionInputs): boolean {
  return inputs.visibilityState === 'visible';
}

export function pollIntervalMs(hasInFlightJob: boolean): number {
  return hasInFlightJob ? ACTIVE_POLL_INTERVAL_MS : IDLE_POLL_INTERVAL_MS;
}

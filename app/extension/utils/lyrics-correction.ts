import type { LyricsCorrectionStatus } from './contracts';

export const LYRICS_CHARACTER_LIMIT = 25000;

export const LYRICS_CORRECTION_STAGES = [
  { key: 'queued', percent: 0, label: 'Waiting to start' },
  { key: 'aligning', percent: 15, label: 'Checking and aligning lyrics' },
  { key: 'rebuilding', percent: 45, label: 'Rebuilding words and translations' },
  { key: 'romanizing', percent: 70, label: 'Rebuilding pronunciation' },
  { key: 'enriching', percent: 85, label: 'Rebuilding word cards' },
  { key: 'finalizing', percent: 95, label: 'Applying replacement' },
] as const;

export function lyricsCorrectionProgress(stage: LyricsCorrectionStatus['stage']): { percent: number; label: string } {
  const progress = LYRICS_CORRECTION_STAGES.find((item) => item.key === stage);

  return progress ?? (stage === 'completed' ? { percent: 100, label: 'Complete' } : { percent: 0, label: 'Waiting to start' });
}

export function lyricsCharacterCount(value: string): number {
  return Array.from(value).length;
}

export function canApplyLyricsCorrection(
  value: string,
  status: LyricsCorrectionStatus | null | undefined,
): boolean {
  const count = lyricsCharacterCount(value);

  return count > 0 && count <= LYRICS_CHARACTER_LIMIT && status?.status !== 'queued' && status?.status !== 'running';
}

/**
 * Per-tab correction state. status is the latest locally accepted correction
 * for the current completed job; latestRequestId grows monotonically so a
 * response from an older request can never overwrite a newer submit or
 * status response. Clearing is a tombstone that bumps the revision instead
 * of deleting the entry, so an in-flight response can never be accepted
 * against a fresh revision counter after the state was cleared.
 */
export interface LyricsCorrectionTabState {
  jobId: string | null;
  status: LyricsCorrectionStatus | null;
  latestRequestId: number;
}

export type LyricsCorrectionSyncAction =
  | { type: 'sync-started'; jobId: string; requestId: number }
  | { type: 'response'; jobId: string; requestId: number; status: LyricsCorrectionStatus }
  | { type: 'submit'; jobId: string; status: LyricsCorrectionStatus }
  | { type: 'cancelled'; jobId: string; attemptId: string; status: LyricsCorrectionStatus }
  | { type: 'cleared' };

export function lyricsCorrectionTabState(): LyricsCorrectionTabState {
  return { jobId: null, status: null, latestRequestId: 0 };
}

export function nextLyricsCorrectionSync(
  state: LyricsCorrectionTabState,
  action: LyricsCorrectionSyncAction,
): LyricsCorrectionTabState {
  switch (action.type) {
    case 'sync-started':
      return {
        jobId: action.jobId,
        status: action.jobId === state.jobId ? state.status : null,
        latestRequestId: action.requestId,
      };

    case 'response':
      if (action.requestId !== state.latestRequestId || action.jobId !== state.jobId) {
        return state;
      }

      return { jobId: action.jobId, status: action.status, latestRequestId: state.latestRequestId };

    case 'submit':
      return { jobId: action.jobId, status: action.status, latestRequestId: state.latestRequestId + 1 };

    case 'cancelled':
      if (state.jobId !== action.jobId || state.status?.attemptId !== action.attemptId) {
        return state;
      }

      return { jobId: action.jobId, status: action.status, latestRequestId: state.latestRequestId + 1 };

    case 'cleared':
      return { ...state, jobId: null, status: null, latestRequestId: state.latestRequestId + 1 };
  }
}

/**
 * One backend synchronization for a tab. syncBackend: false returns the
 * cached value for the current job (including null) without creating a
 * request id or mutating the map, so a local-only panel refresh never
 * invalidates an in-flight backend response. A response is accepted only
 * when its request is still the latest and its job is still active; the
 * server attempt is adopted as-is, so another extension instance's newer
 * attempt is accepted once it is returned by the latest request.
 */
export async function syncLyricsCorrectionStatus(options: {
  tabId: number;
  jobId: string;
  syncBackend: boolean;
  states: Map<number, LyricsCorrectionTabState>;
  fetchStatus: () => Promise<LyricsCorrectionStatus>;
  onCurrentRequestError?: (error: unknown) => void;
}): Promise<LyricsCorrectionStatus | null> {
  const current = options.states.get(options.tabId) ?? lyricsCorrectionTabState();

  if (!options.syncBackend) {
    return current.jobId === options.jobId ? current.status : null;
  }

  const requestId = current.latestRequestId + 1;
  const started = nextLyricsCorrectionSync(current, {
    type: 'sync-started',
    jobId: options.jobId,
    requestId,
  });
  options.states.set(options.tabId, started);

  let correction: LyricsCorrectionStatus;

  try {
    correction = await options.fetchStatus();
  } catch (error) {
    const latest = options.states.get(options.tabId);

    if (latest?.latestRequestId === requestId && latest.jobId === options.jobId) {
      options.onCurrentRequestError?.(error);
    }

    throw error;
  }

  const latest = options.states.get(options.tabId);

  if (!latest) {
    return null;
  }

  const accepted = nextLyricsCorrectionSync(latest, {
    type: 'response',
    jobId: options.jobId,
    requestId,
    status: correction,
  });
  if (accepted !== latest) {
    options.states.set(options.tabId, accepted);
  }

  return accepted.status;
}

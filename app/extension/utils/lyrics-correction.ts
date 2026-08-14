import type { LyricsCorrectionStatus } from './contracts';

export const LYRICS_CHARACTER_LIMIT = 25000;

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
 * Panel-only paste memory. The value lives in the panel textarea and in this
 * buffer; it is never written to extension storage. The buffer is scoped to
 * the active YouTube video so a video change clears the paste while a
 * correction failure on the same video keeps it editable for retry.
 */
export interface LyricsPasteBuffer {
  videoId: string | null;
  value: string;
}

export const EMPTY_LYRICS_PASTE: LyricsPasteBuffer = { videoId: null, value: '' };

export function lyricsPasteForVideo(buffer: LyricsPasteBuffer, videoId: string | null): LyricsPasteBuffer {
  return videoId === buffer.videoId ? buffer : { videoId, value: '' };
}

/**
 * Per-tab correction state. status is the latest locally accepted correction
 * for the current completed job; latestRequestId grows monotonically so a
 * response from an older request can never overwrite a newer submit or
 * status response.
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
      if (action.requestId < state.latestRequestId) {
        return state;
      }

      return { jobId: action.jobId, status: action.status, latestRequestId: state.latestRequestId };

    case 'submit':
      return { jobId: action.jobId, status: action.status, latestRequestId: state.latestRequestId + 1 };

    case 'cleared':
      return { ...state, jobId: null, status: null };
  }
}

/**
 * One backend synchronization for a tab. syncBackend: false returns the
 * cached value (including null) without calling the status endpoint. A
 * response is accepted only when its request is still the latest; the server
 * attempt is adopted as-is, so another extension instance's newer attempt is
 * accepted once it is returned by the latest request.
 */
export async function syncLyricsCorrectionStatus(options: {
  tabId: number;
  jobId: string;
  syncBackend: boolean;
  states: Map<number, LyricsCorrectionTabState>;
  fetchStatus: () => Promise<LyricsCorrectionStatus>;
}): Promise<LyricsCorrectionStatus | null> {
  const current = options.states.get(options.tabId) ?? lyricsCorrectionTabState();
  const requestId = current.latestRequestId + 1;
  const started = nextLyricsCorrectionSync(current, {
    type: 'sync-started',
    jobId: options.jobId,
    requestId,
  });
  options.states.set(options.tabId, started);

  if (!options.syncBackend) {
    return started.status;
  }

  const correction = await options.fetchStatus();
  const latest = options.states.get(options.tabId) ?? started;
  const accepted = nextLyricsCorrectionSync(latest, {
    type: 'response',
    jobId: options.jobId,
    requestId,
    status: correction,
  });
  options.states.set(options.tabId, accepted);

  return accepted.status === correction ? correction : latest.status;
}

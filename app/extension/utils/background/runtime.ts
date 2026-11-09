import { type Browser } from 'wxt/browser';
import { SubtitleApiClient } from '../api';
import type { ClaudeAccount,CodexAccount,InstanceSettings,SubtitleJobHistoryItem } from '../contracts';
import { type LyricsCorrectionTabState } from '../lyrics-correction';
import { type SubtitleState } from '../messages';
import { type YoutubePageInfo } from '../youtube';
export type SupportedYoutubePageInfo = Extract<YoutubePageInfo, {
    supported: true;
}>;
export type PageSnapshotResponse = {
    ok: true;
    videoDurationSeconds?: number;
};
export const runtime = {
subtitleApi: new SubtitleApiClient(),
tabSubtitleStates: new Map<number, SubtitleState>(),
tabSubtitleStateSessions: new Map<number, string>(),
tabOperations: new Map<number, symbol>(),
tabGenerationCancellationInFlight: new Map<number, {
    jobId: string;
    operation: symbol;
}>(),
tabLyricsCorrectionStates: new Map<number, LyricsCorrectionTabState>(),
tabLyricsCorrectionStateSessions: new Map<number, string>(),
tabGenerationInFlight: new Map<number, string>(),
tabCorrectionMutationInFlight: new Map<number, symbol>(),
panelPorts: new Set<Browser.runtime.Port>(),
cachedPanelJobHistory: [] as SubtitleJobHistoryItem[],
cachedPanelJobHistoryError: undefined as string | undefined,
cachedPanelJobHistorySessionId: undefined as string | undefined,
localStateResetVersion: 0,
cachedInstanceSettings: undefined as InstanceSettings | undefined,
instanceSettingsError: undefined as string | undefined,
cachedCodexAccount: undefined as CodexAccount | undefined,
codexAccountError: undefined as string | undefined,
codexAccountVersion: 0,
cachedClaudeAccount: undefined as ClaudeAccount | undefined,
claudeAccountError: undefined as string | undefined,
claudeAccountVersion: 0,
JOB_POLL_INTERVAL_MS: 1000,
JOB_POLL_MAX_FAILURES: 6,
ACTIVE_JOB_POLL_INTERVAL_MS: 1000
};

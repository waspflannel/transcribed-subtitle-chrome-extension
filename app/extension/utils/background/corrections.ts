import { browser,type Browser } from 'wxt/browser';
import { clearTabOperationIfMatches,forgetRememberedTrack,getRememberedTrack,getTabOperation,rememberActiveTrack,setTabOperation } from '../active-tracks';
import { SubtitleApiError } from '../api';
import type { LearningTokenResponse,LyricsCorrectionStatus,TrackResponse } from '../contracts';
import { getInstanceContext,type InstanceContext } from '../instance-context';
import { lyricsCorrectionTabState,nextLyricsCorrectionSync,syncLyricsCorrectionStatus } from '../lyrics-correction';
import { type BackgroundRequest,type PanelState,type SubtitleState } from '../messages';
import { getOrCreateInstallId } from '../settings';
import { trackWithLearningToken } from '../track-tokens';
import { parseYoutubePage,type YoutubePageInfo } from '../youtube';
import { isGenerationInFlight } from './generation';
import { getPanelState } from './panel';
import { runtime } from './runtime';
import { getActiveTab,getSubtitleStateForPage,isCurrentSession,isSubtitleStateForVideo,publishSubtitleState } from './state';

export /**
 * Clearing correction sync state is a tombstone: the per-tab revision keeps
 * increasing so an already in-flight status response can never be accepted
 * against a fresh revision counter or a different job.
 */
function tombstoneLyricsCorrectionState(tabId: number): void {
    const current = runtime.tabLyricsCorrectionStates.get(tabId);
    if (current) {
        runtime.tabLyricsCorrectionStates.set(tabId, nextLyricsCorrectionSync(current, { type: 'cleared' }));
    }
}

export function tombstoneAllLyricsCorrectionStates(): void {
    for (const tabId of [...runtime.tabLyricsCorrectionStates.keys()]) {
        tombstoneLyricsCorrectionState(tabId);
    }
}

export function resetLyricsCorrectionStatesForSession(sessionId?: string): void {
    for (const tabId of [...runtime.tabLyricsCorrectionStates.keys()]) {
        if (sessionId) {
            runtime.tabLyricsCorrectionStateSessions.set(tabId, sessionId);
        }
        else {
            runtime.tabLyricsCorrectionStateSessions.delete(tabId);
        }
    }
    tombstoneAllLyricsCorrectionStates();
}

export async function enrichLearningTokenFromContent(message: Extract<BackgroundRequest, {
    type: 'content.enrichLearningToken';
}>, sender: Browser.runtime.MessageSender): Promise<unknown> {
    const tabId = typeof sender.tab?.id === 'number' ? sender.tab.id : null;
    if (tabId === null) {
        throw new Error('Learning token enrichment requires an active content tab.');
    }
    const session = await getInstanceContext();
    const sessionId = session.sessionId;
    const currentState = await readySubtitleStateForEnrichment(tabId, message.youtubeVideoId, message.trackId, sessionId);
    if (!currentState) {
        throw new Error('No generated subtitle track is active for this tab.');
    }
    const installId = await getOrCreateInstallId();
    const response: LearningTokenResponse = await runtime.subtitleApi.enrichLearningToken(installId, {
        trackId: message.trackId,
        cueId: message.cueId,
        tokenIndex: message.tokenIndex,
    });
    if (!await isCurrentSession(sessionId))
        return { ok: true, stale: true };
    await readySubtitleStateForEnrichment(tabId, message.youtubeVideoId, message.trackId, sessionId);
    if (!await isCurrentSession(sessionId))
        return { ok: true, stale: true };
    const freshState = runtime.tabSubtitleStates.get(tabId);
    if (response.cueId !== message.cueId || response.token.index !== message.tokenIndex || freshState?.type !== 'ready' || freshState.track.trackId !== message.trackId
        || freshState.track.youtubeVideoId !== message.youtubeVideoId
        || !freshState.track.cues.some((cue) => cue.cueId === message.cueId && cue.tokens.some((token) => token.index === message.tokenIndex))) {
        return { ok: true, stale: true };
    }
    const track = trackWithLearningToken(freshState.track, response.cueId, response.token);
    // Keep the final read/merge/write synchronous. A second enrichment reply
    // must see the first reply's token instead of merging both replies into the
    // same pre-await snapshot.
    const nextState: Extract<SubtitleState, {
        type: 'ready';
    }> = { type: 'ready', track };
    runtime.tabSubtitleStates.set(tabId, nextState);
    runtime.tabSubtitleStateSessions.set(tabId, sessionId);
    if (await isCurrentSession(sessionId))
        await rememberActiveTrack(track);
    return {
        ok: true,
        track,
        cueId: response.cueId,
        token: response.token,
    };
}

export async function submitLyricsCorrectionFromPanel(message: Extract<BackgroundRequest, {
    type: 'panel.submitLyricsCorrection';
}>, windowId?: number): Promise<PanelState> {
    const activeTab = await getActiveTab(windowId);
    const tabId = activeTab?.id ?? null;
    const session = await getInstanceContext();
    if (tabId === null || !session || !activeTab) {
        throw new Error('The local state changed. Try again.');
    }
    const pageStatus = parseYoutubePage(activeTab.url ?? '');
    const currentState = await getSubtitleStateForPage(tabId, pageStatus, session.sessionId);
    if (!pageStatus.supported || pageStatus.videoId !== message.youtubeVideoId || currentState.type !== 'ready' || currentState.track.jobId !== message.jobId || currentState.track.trackId !== message.trackId) {
        throw new SubtitleApiError('not_found', 'The active subtitle track has changed. Refresh the panel and try again.', 404);
    }
    if (isGenerationInFlight(tabId, message.youtubeVideoId) || runtime.tabCorrectionMutationInFlight.has(tabId)) {
        throw new SubtitleApiError('lyrics_correction_in_progress', 'A subtitle generation is already in progress.', 409);
    }
    const correctionClaim = Symbol('lyrics-correction');
    runtime.tabCorrectionMutationInFlight.set(tabId, correctionClaim);
    try {
        const sessionId = session.sessionId;
        const installId = await getOrCreateInstallId();
        await setTabOperation(tabId, {
            kind: 'correction',
            youtubeVideoId: message.youtubeVideoId,
            jobId: message.jobId,
            trackId: message.trackId,
        });
        if (!await isCurrentSession(sessionId))
            return getPanelState({ syncBackend: false, windowId });
        let correction: LyricsCorrectionStatus;
        try {
            correction = await runtime.subtitleApi.startLyricsCorrection(installId, message.jobId, {
                lyrics: message.lyrics,
                expectedTrackId: message.trackId,
                ...(message.aiProvider ? { aiProvider: message.aiProvider } : {}),
                ...(message.aiModel !== undefined ? { aiModel: message.aiModel } : {}),
                ...(message.aiFastMode !== undefined ? { aiFastMode: message.aiFastMode } : {}),
            });
        }
        catch (error) {
            if (await isCurrentSession(sessionId)) {
                const operation = await getTabOperation(tabId);
                if (operation?.kind === 'correction'
                    && operation.youtubeVideoId === message.youtubeVideoId && operation.jobId === message.jobId
                    && operation.trackId === message.trackId) {
                    await clearTabOperationIfMatches(tabId, operation);
                }
            }
            throw error;
        }
        if (!await isCurrentSession(sessionId))
            return getPanelState({ syncBackend: false, windowId });
        await setTabOperation(tabId, {
            kind: 'correction',
            youtubeVideoId: message.youtubeVideoId,
            jobId: message.jobId,
            trackId: message.trackId,
            attemptId: correction.attemptId,
        });
        if (!await activeReadyTrackMatches(tabId, message.youtubeVideoId, message.jobId, message.trackId, sessionId)) {
            if (correction.status === 'completed' && correction.track && await isCurrentSession(sessionId)) {
                await rememberActiveTrack(correction.track);
            }
            return getPanelState({ syncBackend: true, windowId });
        }
        runtime.tabLyricsCorrectionStates.set(tabId, nextLyricsCorrectionSync(runtime.tabLyricsCorrectionStates.get(tabId) ?? lyricsCorrectionTabState(), { type: 'submit', jobId: message.jobId, status: correction }));
        runtime.tabLyricsCorrectionStateSessions.set(tabId, sessionId);
        if (correction.status === 'completed' && correction.track) {
            await publishSubtitleState(tabId, { type: 'ready', track: correction.track }, sessionId);
            const operation = await getTabOperation(tabId);
            if (await isCurrentSession(sessionId) && operation?.kind === 'correction'
                && operation.youtubeVideoId === message.youtubeVideoId
                && operation.jobId === message.jobId && operation.trackId === message.trackId
                && operation.attemptId === correction.attemptId) {
                await clearTabOperationIfMatches(tabId, operation);
            }
        }
        return getPanelState({ syncBackend: true, windowId });
    }
    finally {
        if (runtime.tabCorrectionMutationInFlight.get(tabId) === correctionClaim)
            runtime.tabCorrectionMutationInFlight.delete(tabId);
    }
}

export async function cancelLyricsCorrectionFromPanel(message: Extract<BackgroundRequest, {
    type: 'panel.cancelLyricsCorrection';
}>, windowId?: number): Promise<PanelState> {
    const activeTab = await getActiveTab(windowId);
    const tabId = activeTab?.id ?? null;
    const session = await getInstanceContext();
    if (tabId === null || !session || !activeTab) {
        throw new Error('The local state changed. Try again.');
    }
    const pageStatus = parseYoutubePage(activeTab.url ?? '');
    if (!pageStatus.supported || pageStatus.videoId !== message.youtubeVideoId) {
        throw new SubtitleApiError('not_found', 'The active subtitle track has changed. Refresh the panel and try again.', 404);
    }
    if (isGenerationInFlight(tabId, message.youtubeVideoId) || runtime.tabCorrectionMutationInFlight.has(tabId)) {
        throw new SubtitleApiError('lyrics_correction_in_progress', 'A subtitle generation is already in progress.', 409);
    }
    const correctionClaim = Symbol('lyrics-correction');
    runtime.tabCorrectionMutationInFlight.set(tabId, correctionClaim);
    try {
        const sessionId = session.sessionId;
        const installId = await getOrCreateInstallId();
        const correction = await runtime.subtitleApi.cancelLyricsCorrection(installId, message.jobId, { attemptId: message.attemptId });
        if (!await isCurrentSession(sessionId))
            return getPanelState({ syncBackend: false, windowId });
        if (!await activeReadyTrackMatches(tabId, message.youtubeVideoId, message.jobId, message.trackId, sessionId)) {
            if (correction.status === 'completed' && correction.track && await isCurrentSession(sessionId)) {
                await rememberActiveTrack(correction.track);
            }
            return getPanelState({ syncBackend: true, windowId });
        }
        const currentCorrection = runtime.tabLyricsCorrectionStates.get(tabId);
        if (!currentCorrection || currentCorrection.jobId !== message.jobId || currentCorrection.status?.attemptId !== message.attemptId) {
            return getPanelState({ syncBackend: true, windowId });
        }
        runtime.tabLyricsCorrectionStates.set(tabId, nextLyricsCorrectionSync(currentCorrection, { type: 'cancelled', jobId: message.jobId, attemptId: message.attemptId, status: correction }));
        runtime.tabLyricsCorrectionStateSessions.set(tabId, sessionId);
        if (correction.status === 'completed' && correction.track) {
            await publishSubtitleState(tabId, { type: 'ready', track: correction.track }, sessionId);
        }
        const operation = await getTabOperation(tabId);
        if (await isCurrentSession(sessionId) && operation?.kind === 'correction'
            && operation.youtubeVideoId === message.youtubeVideoId
            && operation.jobId === message.jobId && operation.trackId === message.trackId
            && operation.attemptId === message.attemptId) {
            await clearTabOperationIfMatches(tabId, operation);
        }
        return getPanelState({ syncBackend: true, windowId });
    }
    finally {
        if (runtime.tabCorrectionMutationInFlight.get(tabId) === correctionClaim)
            runtime.tabCorrectionMutationInFlight.delete(tabId);
    }
}

export async function quickFixTokenFromPanel(message: Extract<BackgroundRequest, {
    type: 'panel.quickFixToken';
}>, windowId?: number): Promise<PanelState> {
    const activeTab = await getActiveTab(windowId);
    const tabId = activeTab?.id ?? null;
    const session = await getInstanceContext();
    if (tabId === null || !session || !activeTab) {
        throw new Error('The local state changed. Try again.');
    }
    const pageStatus = parseYoutubePage(activeTab.url ?? '');
    const currentState = await getSubtitleStateForPage(tabId, pageStatus, session.sessionId);
    if (!pageStatus.supported || pageStatus.videoId !== message.youtubeVideoId || currentState.type !== 'ready' || currentState.track.jobId !== message.jobId || currentState.track.trackId !== message.trackId) {
        throw new SubtitleApiError('not_found', 'The active subtitle track has changed. Refresh the panel and try again.', 404);
    }
    if (isGenerationInFlight(tabId, message.youtubeVideoId) || runtime.tabCorrectionMutationInFlight.has(tabId)) {
        throw new SubtitleApiError('lyrics_correction_in_progress', 'A subtitle generation is already in progress.', 409);
    }
    const correctionClaim = Symbol('lyrics-correction');
    runtime.tabCorrectionMutationInFlight.set(tabId, correctionClaim);
    try {
        const sessionId = session.sessionId;
        const installId = await getOrCreateInstallId();
        let track: TrackResponse;
        try {
            track = await runtime.subtitleApi.quickFixToken(installId, message.jobId, message.cueId, message.tokenIndex, { expectedTrackId: message.trackId, text: message.text });
        }
        catch (error) {
            if (error instanceof SubtitleApiError && error.code === 'lyrics_correction_in_progress' && error.details?.reason === 'stale_track') {
                if (await activeReadyTrackMatches(tabId, message.youtubeVideoId, message.jobId, message.trackId, sessionId)) {
                    runtime.tabSubtitleStates.delete(tabId);
                    runtime.tabSubtitleStateSessions.delete(tabId);
                    await forgetRememberedTrack(message.youtubeVideoId, message.trackId);
                    await recoverExactJobAfterStaleTrack(tabId, message, installId, sessionId);
                }
            }
            throw error;
        }
        if (!await isCurrentSession(sessionId))
            return getPanelState({ syncBackend: false, windowId });
        if (!await activeReadyTrackMatches(tabId, message.youtubeVideoId, message.jobId, message.trackId, sessionId)) {
            if (await isCurrentSession(sessionId))
                await rememberActiveTrack(track);
            return getPanelState({ syncBackend: true, windowId });
        }
        await publishSubtitleState(tabId, { type: 'ready', track }, sessionId);
        return getPanelState({ syncBackend: true, windowId });
    }
    finally {
        if (runtime.tabCorrectionMutationInFlight.get(tabId) === correctionClaim)
            runtime.tabCorrectionMutationInFlight.delete(tabId);
    }
}

export async function recoverExactJobAfterStaleTrack(tabId: number, message: Extract<BackgroundRequest, {
    type: 'panel.quickFixToken';
}>, installId: string, sessionId: string): Promise<void> {
    try {
        const session = await getInstanceContext();
        if (!session || session.sessionId !== sessionId)
            return;
        const job = await runtime.subtitleApi.getSubtitleJob(installId, message.jobId);
        if (job.status !== 'completed' || !job.track)
            return;
        if (!await isCurrentSession(session.sessionId))
            return;
        await rememberActiveTrack(job.track);
        const tab = await browser.tabs.get(tabId).catch(() => undefined);
        const pageStatus = tab ? parseYoutubePage(tab.url ?? '') : undefined;
        if (pageStatus?.supported && pageStatus.videoId === message.youtubeVideoId) {
            await publishSubtitleState(tabId, { type: 'ready', track: job.track }, sessionId);
        }
    }
    catch {
        // The original stale-track error remains the user-visible result.
    }
}

export async function activeReadyTrackMatches(tabId: number, youtubeVideoId: string, jobId: string, trackId: string, expectedSessionId?: string): Promise<boolean> {
    if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId))
        return false;
    const activeTab = await browser.tabs.get(tabId).catch(() => undefined);
    if (activeTab === undefined) {
        return false;
    }
    const pageStatus = parseYoutubePage(activeTab.url ?? '');
    if (!pageStatus.supported || pageStatus.videoId !== youtubeVideoId) {
        return false;
    }
    const currentState = await getSubtitleStateForPage(tabId, pageStatus, expectedSessionId);
    if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId))
        return false;
    return currentState.type === 'ready'
        && currentState.track.jobId === jobId
        && currentState.track.trackId === trackId;
}

export async function readySubtitleStateForEnrichment(tabId: number, youtubeVideoId: string, trackId: string, expectedSessionId?: string): Promise<Extract<SubtitleState, {
    type: 'ready';
}> | null> {
    const currentState = runtime.tabSubtitleStates.get(tabId);
    const ownsState = (expectedSessionId === undefined || runtime.tabSubtitleStateSessions.get(tabId) === expectedSessionId);
    if (ownsState && currentState?.type === 'ready' && currentState.track.trackId === trackId) {
        if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId))
            return null;
        const currentTab = await browser.tabs.get(tabId).catch(() => undefined);
        const currentPage = parseYoutubePage(currentTab?.url ?? '');
        if (!currentPage.supported || currentPage.videoId !== youtubeVideoId)
            return null;
        return currentState;
    }
    if (ownsState && currentState && currentState.type !== 'no-track' && isSubtitleStateForVideo(currentState, youtubeVideoId)) {
        return null;
    }
    const operation = runtime.tabOperations.get(tabId);
    const mutation = runtime.tabCorrectionMutationInFlight.get(tabId);
    const rememberedTrack = await getRememberedTrack(youtubeVideoId);
    if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId))
        return null;
    const currentTab = await browser.tabs.get(tabId).catch(() => undefined);
    const currentPage = parseYoutubePage(currentTab?.url ?? '');
    if (!currentPage.supported || currentPage.videoId !== youtubeVideoId)
        return null;
    if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId))
        return null;
    if (runtime.tabOperations.get(tabId) !== operation || runtime.tabCorrectionMutationInFlight.get(tabId) !== mutation)
        return null;
    const latestState = runtime.tabSubtitleStates.get(tabId);
    if (latestState !== currentState) {
        return latestState?.type === 'ready' && latestState.track.trackId === trackId
            && latestState.track.youtubeVideoId === youtubeVideoId
            && (expectedSessionId === undefined || runtime.tabSubtitleStateSessions.get(tabId) === expectedSessionId) ? latestState : null;
    }
    if (rememberedTrack?.trackId === trackId) {
        const restoredState: Extract<SubtitleState, {
            type: 'ready';
        }> = { type: 'ready', track: rememberedTrack };
        runtime.tabSubtitleStates.set(tabId, restoredState);
        if (expectedSessionId)
            runtime.tabSubtitleStateSessions.set(tabId, expectedSessionId);
        return restoredState;
    }
    return null;
}

export async function syncLyricsCorrection(tabId: number | null, pageStatus: YoutubePageInfo | undefined, session: InstanceContext | null, installId: string, syncBackend: boolean, subtitleState: SubtitleState): Promise<LyricsCorrectionStatus | null> {
    if (tabId === null || !pageStatus?.supported || !session) {
        if (syncBackend && tabId !== null) {
            runtime.tabLyricsCorrectionStateSessions.delete(tabId);
            tombstoneLyricsCorrectionState(tabId);
        }
        return null;
    }
    if (runtime.tabLyricsCorrectionStateSessions.get(tabId) !== session.sessionId) {
        runtime.tabLyricsCorrectionStates.set(tabId, lyricsCorrectionTabState());
    }
    runtime.tabLyricsCorrectionStateSessions.set(tabId, session.sessionId);
    const persistedOperation = await getTabOperation(tabId);
    ;
    const trackedJobId = subtitleState.type === 'ready'
        ? subtitleState.track.jobId
        : runtime.tabLyricsCorrectionStates.get(tabId)?.jobId ?? (persistedOperation?.kind === 'correction' ? persistedOperation.jobId : undefined);
    if (!trackedJobId) {
        if (syncBackend) {
            tombstoneLyricsCorrectionState(tabId);
        }
        return null;
    }
    try {
        const sessionId = session.sessionId;
        let completedResult: LyricsCorrectionStatus | undefined;
        const status = await syncLyricsCorrectionStatus({
            tabId,
            jobId: trackedJobId,
            syncBackend,
            states: runtime.tabLyricsCorrectionStates,
            onCompleted: (result) => { completedResult = result; },
            fetchStatus: () => runtime.subtitleApi.getLyricsCorrectionStatus(installId, trackedJobId),
            canCommit: () => runtime.tabLyricsCorrectionStateSessions.get(tabId) === sessionId,
            onCurrentRequestError: (error) => {
                if (error instanceof SubtitleApiError && error.code === 'not_found') {
                    const failedState = runtime.tabLyricsCorrectionStates.get(tabId);
                    void isCurrentSession(sessionId).then((current) => {
                        if (current && runtime.tabLyricsCorrectionStates.get(tabId) === failedState
                            && runtime.tabLyricsCorrectionStateSessions.get(tabId) === sessionId)
                            tombstoneLyricsCorrectionState(tabId);
                    });
                }
            },
        });
        if (!await isCurrentSession(sessionId))
            return null;
        const latestCorrection = runtime.tabLyricsCorrectionStates.get(tabId);
        if (completedResult?.status === 'completed' && completedResult.track && latestCorrection?.jobId === trackedJobId
            && latestCorrection.status?.status === 'completed' && latestCorrection.status.attemptId === completedResult.attemptId) {
            const currentSubtitleState = runtime.tabSubtitleStates.get(tabId);
            const currentTrack = currentSubtitleState?.type === 'ready' ? currentSubtitleState.track : null;
            if (currentTrack && await activeReadyTrackMatches(tabId, pageStatus.videoId, trackedJobId, currentTrack.trackId, sessionId)) {
                if (await isCurrentSession(sessionId)) {
                    await publishSubtitleState(tabId, { type: 'ready', track: completedResult.track }, session.sessionId);
                }
            }
            else if (await isCurrentSession(sessionId)) {
                await rememberActiveTrack(completedResult.track);
            }
        }
        if (status && !['queued', 'running'].includes(status.status)) {
            const operation = await getTabOperation(tabId);
            if (await isCurrentSession(session.sessionId) && runtime.tabLyricsCorrectionStates.get(tabId) === latestCorrection
                && latestCorrection?.status === status && operation?.kind === 'correction'
                && operation.youtubeVideoId === pageStatus.videoId
                && operation.jobId === trackedJobId && operation.attemptId === status.attemptId) {
                await clearTabOperationIfMatches(tabId, operation);
            }
        }
        return status;
    }
    catch {
        return await isCurrentSession(session.sessionId) ? runtime.tabLyricsCorrectionStates.get(tabId)?.status ?? null : null;
    }
}

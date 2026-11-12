import { browser,type Browser } from 'wxt/browser';
import { clearTabOperationIfMatches,forgetRememberedTrack,getRememberedTrack,getTabOperation,rememberActiveTrack,setTabOperation } from '../active-tracks';
import { publicSubtitleErrorMessage,SubtitleApiError } from '../api';
import { stateWithBackendProgress } from '../backend-subtitle-state';
import type { SubtitleJobHistoryItem,TrackResponse } from '../contracts';
import { getInstanceContext,type InstanceContext } from '../instance-context';
import { DEFAULT_SUBTITLE_STATE,type BackgroundRequest,type ContentRequest,type PageSnapshot,type PanelState,type SubtitleState } from '../messages';
import { getExtensionSettings,getOrCreateInstallId,isSubtitleRecoveryBlocked } from '../settings';
import { type ExtensionSettings } from '../settings-model';
import { parseYoutubePage,type YoutubePageInfo } from '../youtube';
import { cancelLyricsCorrectionFromPanel,enrichLearningTokenFromContent,quickFixTokenFromPanel,submitLyricsCorrectionFromPanel,tombstoneLyricsCorrectionState } from './corrections';
import { changeSavedGenerationFromPanel,clearCancelledGenerationState,ensureRecoveredGenerationMonitor,generateSubtitlesFromPanel,isGenerationInFlight,resolveCompletedSubtitleJob } from './generation';
import { clearLocalStateFromPanel,getPanelJobHistory,getPanelState,updateSettingsFromContent,updateSettingsFromPanel } from './panel';
import { flushJobDeletes,isPendingDelete,scheduleJobDeletes,undoJobDeletes } from './pending-deletes';
import { runtime,type PageSnapshotResponse,type SupportedYoutubePageInfo } from './runtime';

export async function handleRuntimeMessage(message: BackgroundRequest, sender: Browser.runtime.MessageSender): Promise<unknown> {
    switch (message.type) {
        case 'panel.getCodexAccount':
        case 'panel.loginCodex':
        case 'panel.disconnectCodex': {
            if (sender.tab)
                throw new Error('Codex sign-in can only be managed from the extension panel.');
            const version = ++runtime.codexAccountVersion;
            const installId = await getOrCreateInstallId();
            const response = message.type === 'panel.loginCodex' ? runtime.subtitleApi.loginCodex(installId)
                : message.type === 'panel.disconnectCodex' ? runtime.subtitleApi.disconnectCodex(installId)
                    : runtime.subtitleApi.getCodexAccount(installId);
            const account = await response.catch(error => {
                throw new Error(error instanceof SubtitleApiError || error instanceof TypeError
                    ? publicSubtitleErrorMessage(error, 'codex') : 'Unable to load Codex. Check the backend and refresh.');
            });
            if (version === runtime.codexAccountVersion) {
                runtime.cachedCodexAccount = account;
                runtime.codexAccountError = undefined;
            }
            return account;
        }
        case 'panel.getClaudeAccount':
        case 'panel.loginClaude':
        case 'panel.submitClaudeCode':
        case 'panel.disconnectClaude': {
            if (sender.tab)
                throw new Error('Claude sign-in can only be managed from the extension panel.');
            const version = ++runtime.claudeAccountVersion;
            const installId = await getOrCreateInstallId();
            const response = message.type === 'panel.loginClaude' ? runtime.subtitleApi.loginClaude(installId)
                : message.type === 'panel.submitClaudeCode' ? runtime.subtitleApi.submitClaudeCode(installId, message.code.trim())
                    : message.type === 'panel.disconnectClaude' ? runtime.subtitleApi.disconnectClaude(installId)
                        : runtime.subtitleApi.getClaudeAccount(installId);
            const account = await response.catch(error => {
                throw new Error(error instanceof SubtitleApiError || error instanceof TypeError
                    ? publicSubtitleErrorMessage(error, 'claude') : 'Unable to load Claude. Check the backend and refresh.');
            });
            if (version === runtime.claudeAccountVersion) {
                runtime.cachedClaudeAccount = account;
                runtime.claudeAccountError = undefined;
            }
            return account;
        }
        case 'content.getState':
            return getContentState(sender, message.revalidateSavedGeneration);
        case 'content.updateSettings':
            return updateSettingsFromContent(message.patch);
        case 'content.enrichLearningToken':
            return enrichLearningTokenFromContent(message, sender);
        case 'panel.getState':
            return getPanelState({ syncBackend: message.syncBackend ?? true, syncLyricsCorrection: message.syncLyricsCorrection, windowId: message.windowId });
        case 'panel.getActiveCue': {
            const tab = await browser.tabs.get(message.tabId);
            const page = parseYoutubePage(tab.url ?? '');
            if (!page.supported || page.videoId !== message.youtubeVideoId
                || (typeof message.windowId === 'number' && tab.windowId !== message.windowId))
                return { ok: false };
            const snapshot = await browser.tabs.sendMessage(message.tabId, {
                type: 'background.getActiveCue', youtubeVideoId: message.youtubeVideoId, trackId: message.trackId,
            });
            return { ...snapshot, tabId: message.tabId, windowId: tab.windowId };
        }
        case 'panel.updateSettings':
            return updateSettingsFromPanel(message.patch, message.windowId);
        case 'panel.listGenerations': {
            const session = await getInstanceContext();
            const tab = await getActiveTab(message.windowId);
            const page = parseYoutubePage(tab?.url ?? '');
            const state = tab?.id !== undefined && page.supported && page.videoId === message.youtubeVideoId
                ? await getSubtitleStateForPage(tab.id, page, session.sessionId) : undefined;
            const installId = await getOrCreateInstallId();
            const listed = await runtime.subtitleApi.listSubtitleJobs(installId, message.youtubeVideoId);
            const response = { ...listed, jobs: listed.jobs.filter(job => !isPendingDelete(job.jobId)) };
            if (!await isCurrentSession(session.sessionId))
                throw new Error('Your backend changed. Refresh the panel.');
            if (tab?.id !== undefined && page.supported && state?.type === 'ready'
                && runtime.tabSubtitleStates.get(tab.id) === state
                && !isGenerationInFlight(tab.id, page.videoId) && !runtime.tabCorrectionMutationInFlight.has(tab.id)) {
                const checked = await revalidateSavedTrack(tab.id, page, state, installId, session, response.jobs);
                if (checked !== state && runtime.tabSubtitleStates.get(tab.id) === checked) {
                    const recovered = checked.type === 'no-track'
                        ? await recoverSubtitleStateFromBackend(tab.id, page, checked, installId, session) : checked;
                    const currentTab = await browser.tabs.get(tab.id).catch(() => undefined);
                    const currentPage = parseYoutubePage(currentTab?.url ?? '');
                    if (await isCurrentSession(session.sessionId) && currentPage.supported && currentPage.videoId === page.videoId
                        && runtime.tabSubtitleStates.get(tab.id) === recovered) {
                        await sendTabMessage(tab.id, { type: 'background.subtitleStateChanged', subtitleState: recovered });
                    }
                    return { ...response, panelState: await getPanelState({ syncBackend: false, windowId: message.windowId }) };
                }
            }
            return response;
        }
        case 'panel.selectGeneration':
        case 'panel.deleteGeneration':
            return changeSavedGenerationFromPanel(message);
        case 'panel.deleteJobs':
            scheduleJobDeletes(message.jobs);
            return getPanelState({ syncBackend: false, windowId: message.windowId });
        case 'panel.undoDeleteJobs':
            undoJobDeletes(message.jobIds);
            return getPanelState({ syncBackend: false, windowId: message.windowId });
        case 'panel.flushDeletes':
            await flushJobDeletes();
            return getPanelState({ syncBackend: false, windowId: message.windowId });
        case 'panel.generateSubtitles':
            return generateSubtitlesFromPanel(message.youtubeVideoId, message.tabId, message.windowId);
        case 'panel.cancelSubtitleJob':
            return cancelSubtitleJobFromPanel(message, message.windowId);
        case 'panel.submitLyricsCorrection':
            return submitLyricsCorrectionFromPanel(message, message.windowId);
        case 'panel.cancelLyricsCorrection':
            return cancelLyricsCorrectionFromPanel(message, message.windowId);
        case 'panel.quickFixToken':
            return quickFixTokenFromPanel(message, message.windowId);
        case 'panel.saveInstanceSettings':
            if (sender.tab)
                throw new Error('Provider settings can only be changed from the extension panel.');
            runtime.cachedInstanceSettings = await runtime.subtitleApi.updateInstanceSettings(await getOrCreateInstallId(), message.patch);
            return getPanelState({ syncBackend: true, windowId: message.windowId });
        case 'panel.clearLocalState':
            return clearLocalStateFromPanel(message.windowId);
        case 'content.activeCueChanged':
            if (runtime.panelPorts.size > 0 && typeof sender.tab?.id === 'number' && typeof message.trackId === 'string') {
                void browser.runtime.sendMessage({
                    type: 'background.activeCueChanged',
                    cueId: message.cueId,
                    youtubeVideoId: message.youtubeVideoId,
                    trackId: message.trackId,
                    tabId: sender.tab.id,
                    ...(typeof sender.tab.windowId === 'number' ? { windowId: sender.tab.windowId } : {}),
                }).catch(() => { });
            }
            return { ok: true };
        case 'content.focusPanelTranscript':
            void browser.runtime.sendMessage({
                type: 'background.focusTranscript',
                ...(typeof sender.tab?.windowId === 'number' ? { windowId: sender.tab.windowId } : {}),
            }).catch(() => { });
            return { ok: true };
        case 'panel.seekToCue': {
            const tab = await browser.tabs.get(message.tabId).catch(() => undefined);
            const page = parseYoutubePage(tab?.url ?? '');
            if (!tab || !page.supported || page.videoId !== message.youtubeVideoId
                || (typeof message.windowId === 'number' && tab.windowId !== message.windowId)) {
                return { ok: false };
            }
            await sendTabMessage(message.tabId, {
                type: 'background.seekToCue',
                youtubeVideoId: message.youtubeVideoId,
                trackId: message.trackId,
                cueId: message.cueId,
                mode: message.mode,
            });
            return { ok: true };
        }
        default:
            return assertNever(message);
    }
}

export async function getContentState(sender: Browser.runtime.MessageSender, revalidateSavedGeneration = true): Promise<{
    installId: string;
    settings: ExtensionSettings;
    subtitleState: SubtitleState;
}> {
    const tabId = typeof sender.tab?.id === 'number' ? sender.tab.id : null;
    const pageStatus = parseYoutubePage(sender.tab?.url ?? '');
    const installId = await getOrCreateInstallId();
    const session = await getInstanceContext();
    const settings = await getExtensionSettings();
    if (revalidateSavedGeneration && tabId !== null && runtime.tabSubtitleStates.get(tabId)?.type === 'error') {
        runtime.tabSubtitleStates.delete(tabId);
    }
    let subtitleState = tabId === null ? DEFAULT_SUBTITLE_STATE : await getSubtitleStateForPage(tabId, pageStatus, session?.sessionId);
    const initialState = subtitleState;
    if (revalidateSavedGeneration && tabId !== null && pageStatus.supported && session && subtitleState.type === 'ready') {
        subtitleState = await revalidateSavedTrack(tabId, pageStatus, subtitleState, installId, session);
    }
    if (tabId !== null && subtitleState.type === 'no-track' && pageStatus.supported) {
        subtitleState = await recoverSubtitleStateFromBackend(tabId, pageStatus, subtitleState, installId, session);
    }
    const currentSession = await getInstanceContext();
    if (session?.sessionId !== currentSession?.sessionId) {
        subtitleState = DEFAULT_SUBTITLE_STATE;
    }
    if (revalidateSavedGeneration && tabId !== null && subtitleState !== initialState
        && runtime.tabSubtitleStates.get(tabId) === subtitleState && session?.sessionId === currentSession?.sessionId) {
        const tab = await browser.tabs.get(tabId).catch(() => undefined);
        const currentPage = parseYoutubePage(tab?.url ?? '');
        if (currentPage.supported && pageStatus.supported && currentPage.videoId === pageStatus.videoId) {
            await sendTabMessage(tabId, { type: 'background.subtitleStateChanged', subtitleState });
        }
    }
    return { installId, settings, subtitleState };
}

export /** Page entry validates saved lyrics once; normal panel reads keep using local state. */
async function revalidateSavedTrack(tabId: number, page: SupportedYoutubePageInfo, state: Extract<SubtitleState, {
    type: 'ready';
}>, installId: string, session: InstanceContext, savedJobs?: SubtitleJobHistoryItem[]): Promise<SubtitleState> {
    const resetVersion = runtime.localStateResetVersion;
    const operation = runtime.tabOperations.get(tabId);
    const mutation = runtime.tabCorrectionMutationInFlight.get(tabId);
    const stillCurrent = async (): Promise<boolean> => {
        const tab = await browser.tabs.get(tabId).catch(() => undefined);
        const currentPage = parseYoutubePage(tab?.url ?? '');
        return await isCurrentSession(session.sessionId) && resetVersion === runtime.localStateResetVersion
            && currentPage.supported && currentPage.videoId === page.videoId
            && runtime.tabSubtitleStates.get(tabId) === state && runtime.tabOperations.get(tabId) === operation
            && runtime.tabCorrectionMutationInFlight.get(tabId) === mutation;
    };
    let validatedTrack: TrackResponse | undefined;
    try {
        if (!await stillCurrent())
            return getSubtitleStateForPage(tabId, page, session.sessionId);
        const savedJob = savedJobs?.find(job => job.jobId === state.track.jobId);
        // Track IDs change on corrections. An unchanged identity retains locally fetched word cards.
        if (savedJob?.trackId === state.track.trackId)
            return state;
        if (!savedJobs || savedJob) {
            const job = await runtime.subtitleApi.getSubtitleJob(installId, state.track.jobId);
            if (job.jobId === state.track.jobId && job.status === 'completed' && job.track
                && job.track.jobId === state.track.jobId && job.track.youtubeVideoId === page.videoId
                && (job.track.expiresAt === null || Date.parse(job.track.expiresAt) > Date.now()))
                validatedTrack = job.track;
        }
    }
    catch (error) {
        if (!(error instanceof SubtitleApiError && error.code === 'not_found')) {
            // A network failure cannot confirm saved lyrics still exist. Keep the cache for a later retry.
            if (await stillCurrent()) {
                const failed: SubtitleState = { type: 'error', youtubeVideoId: page.videoId,
                    message: 'Unable to check saved generations. Refresh the page to try again.' };
                await publishSubtitleState(tabId, failed, session.sessionId);
                return failed;
            }
            return getSubtitleStateForPage(tabId, page, session.sessionId);
        }
    }
    if (!await stillCurrent())
        return getSubtitleStateForPage(tabId, page, session.sessionId);
    if (validatedTrack) {
        if (validatedTrack.trackId === state.track.trackId)
            return state;
        tombstoneLyricsCorrectionState(tabId);
        const refreshed: SubtitleState = { type: 'ready', track: validatedTrack };
        runtime.tabSubtitleStates.set(tabId, refreshed);
        await rememberActiveTrack(validatedTrack);
        return refreshed;
    }
    await forgetRememberedTrack(page.videoId, state.track.trackId);
    if (!await stillCurrent())
        return getSubtitleStateForPage(tabId, page, session.sessionId);
    tombstoneLyricsCorrectionState(tabId);
    runtime.tabSubtitleStates.set(tabId, DEFAULT_SUBTITLE_STATE);
    return DEFAULT_SUBTITLE_STATE;
}

export /** Global history includes obsolete completed versions; the video list contains readable tracks. */
async function savedTrackRecoveryJobs(state: SubtitleState, page: SupportedYoutubePageInfo, jobs: SubtitleJobHistoryItem[], installId: string, session: InstanceContext): Promise<SubtitleJobHistoryItem[]> {
    if (state.type !== 'no-track' || jobs.some((job) => job.youtubeVideoId === page.videoId
        && (job.status === 'queued' || job.status === 'running')))
        return jobs;
    try {
        const saved = await runtime.subtitleApi.listSubtitleJobs(installId, page.videoId);
        return [...saved.jobs, ...jobs.filter((job) => job.youtubeVideoId !== page.videoId || job.status !== 'completed')];
    }
    catch (error) {
        return jobs;
    }
}

export async function recoverSubtitleStateFromBackend(tabId: number, pageStatus: SupportedYoutubePageInfo, localState: SubtitleState, installId: string, session: InstanceContext | null): Promise<SubtitleState> {
    const originalOperation = runtime.tabOperations.get(tabId);
    const originalState = runtime.tabSubtitleStates.get(tabId);
    if (!session) {
        return localState;
    }
    if (await isSubtitleRecoveryBlocked())
        return localState;
    const history = await getPanelJobHistory(installId, session, true);
    const recoveryJobs = await savedTrackRecoveryJobs(localState, pageStatus, history.jobs, installId, session);
    const resolved = await stateWithBackendProgress(localState, pageStatus, recoveryJobs, (job) => resolveCompletedSubtitleJob(installId, job, session.sessionId));
    const isCurrentRecoveryOwner = async (): Promise<boolean> => {
        if (!await isCurrentSession(session.sessionId) || await isSubtitleRecoveryBlocked())
            return false;
        const currentTab = await browser.tabs.get(tabId).catch(() => undefined);
        const currentPage = parseYoutubePage(currentTab?.url ?? '');
        return currentPage.supported
            && currentPage.videoId === pageStatus.videoId
            && runtime.tabOperations.get(tabId) === originalOperation
            && runtime.tabSubtitleStates.get(tabId) === originalState;
    };
    if (!await isCurrentRecoveryOwner())
        return localState;
    if (resolved.type === 'ready') {
        runtime.tabSubtitleStates.set(tabId, resolved);
        runtime.tabSubtitleStateSessions.set(tabId, session.sessionId);
        if (await isCurrentSession(session.sessionId)) {
            await rememberActiveTrack(resolved.track);
        }
        if (!await isCurrentSession(session.sessionId)
            && runtime.tabSubtitleStates.get(tabId) === resolved
            && runtime.tabSubtitleStateSessions.get(tabId) === session.sessionId
            && runtime.tabOperations.get(tabId) === originalOperation) {
            runtime.tabSubtitleStates.delete(tabId);
            runtime.tabSubtitleStateSessions.delete(tabId);
        }
        return resolved;
    }
    if (resolved.type === 'loading') {
        let monitorRecoveredJob = false;
        if (resolved.jobId && !isGenerationInFlight(tabId, pageStatus.videoId)) {
            const persistedOperation = await getTabOperation(tabId);
            if (!await isCurrentRecoveryOwner())
                return localState;
            if (persistedOperation?.kind === 'generation'
                && persistedOperation.youtubeVideoId === pageStatus.videoId && persistedOperation.jobId === resolved.jobId) {
                monitorRecoveredJob = true;
            }
            else if (!persistedOperation) {
                if (persistedOperation)
                    await clearTabOperationIfMatches(tabId, persistedOperation);
                if (!await isCurrentRecoveryOwner())
                    return localState;
                await setTabOperation(tabId, {
                    kind: 'generation',
                    youtubeVideoId: pageStatus.videoId,
                    jobId: resolved.jobId,
                });
                if (!await isCurrentRecoveryOwner())
                    return localState;
                monitorRecoveredJob = true;
            }
        }
        if (!await isCurrentRecoveryOwner())
            return localState;
        runtime.tabSubtitleStates.set(tabId, resolved);
        runtime.tabSubtitleStateSessions.set(tabId, session.sessionId);
        if (monitorRecoveredJob)
            ensureRecoveredGenerationMonitor(tabId, pageStatus, installId, session);
        return resolved;
    }
    return localState;
}

export async function cancelSubtitleJobFromPanel(message: Extract<BackgroundRequest, {
    type: 'panel.cancelSubtitleJob';
}>, windowId?: number): Promise<PanelState> {
    const session = await getInstanceContext();
    let capturedTab: Browser.tabs.Tab | undefined;
    let capturedOperation: Awaited<ReturnType<typeof getTabOperation>> = null;
    let cancellationOperation: symbol | undefined;
    if (typeof message.tabId === 'number') {
        capturedTab = await browser.tabs.get(message.tabId).catch(() => undefined);
        const pageStatus = parseYoutubePage(capturedTab?.url ?? '');
        if (!capturedTab || !pageStatus.supported || pageStatus.videoId !== message.youtubeVideoId
            || (typeof windowId === 'number' && capturedTab.windowId !== windowId)) {
            throw new SubtitleApiError('not_found', 'The video for this generation is no longer open. Refresh the panel and try again.', 404);
        }
        capturedOperation = await getTabOperation(message.tabId);
        const currentState = await getSubtitleStateForPage(message.tabId, pageStatus, session.sessionId);
        const ownsCurrentJob = (currentState.type === 'loading' && currentState.youtubeVideoId === message.youtubeVideoId
            && currentState.jobId === message.jobId)
            || (capturedOperation?.kind === 'generation'
                && capturedOperation.youtubeVideoId === message.youtubeVideoId
                && capturedOperation.jobId === message.jobId);
        if (!ownsCurrentJob) {
            throw new SubtitleApiError('not_found', 'This generation is no longer active in the selected video.', 404);
        }
        cancellationOperation = runtime.tabOperations.get(message.tabId) ?? Symbol('cancelled-generation');
        runtime.tabOperations.set(message.tabId, cancellationOperation);
        runtime.tabGenerationCancellationInFlight.set(message.tabId, { jobId: message.jobId, operation: cancellationOperation });
    }
    const sessionId = session.sessionId;
    try {
        const cancelled = await runtime.subtitleApi.cancelSubtitleJob(await getOrCreateInstallId(), message.jobId);
        if (cancelled.status !== 'cancelled') {
            throw new Error('Cancelled subtitle job returned a non-cancelled status.');
        }
        if (!await isCurrentSession(sessionId)) {
            if (typeof message.tabId === 'number' && cancellationOperation
                && runtime.tabGenerationCancellationInFlight.get(message.tabId)?.jobId === message.jobId
                && runtime.tabGenerationCancellationInFlight.get(message.tabId)?.operation === cancellationOperation) {
                runtime.tabGenerationCancellationInFlight.delete(message.tabId);
            }
            return getPanelState({ syncBackend: false, windowId });
        }
        if (typeof message.tabId === 'number' && capturedTab) {
            await clearCancelledGenerationState(message.tabId, message.jobId, message.youtubeVideoId, sessionId, cancellationOperation!);
            if (cancellationOperation && runtime.tabGenerationCancellationInFlight.get(message.tabId)?.operation === cancellationOperation) {
                runtime.tabGenerationCancellationInFlight.delete(message.tabId);
            }
        }
        return getPanelState({ syncBackend: true, windowId });
    }
    catch (error) {
        if (typeof message.tabId === 'number' && cancellationOperation
            && runtime.tabGenerationCancellationInFlight.get(message.tabId)?.jobId === message.jobId
            && runtime.tabGenerationCancellationInFlight.get(message.tabId)?.operation === cancellationOperation) {
            runtime.tabGenerationCancellationInFlight.delete(message.tabId);
            if (!runtime.tabGenerationInFlight.has(message.tabId) && runtime.tabOperations.get(message.tabId) === cancellationOperation) {
                runtime.tabOperations.delete(message.tabId);
            }
        }
        throw error;
    }
}

export async function getPageSnapshotFromTab(tabId: number): Promise<PageSnapshot> {
    try {
        const response = (await browser.tabs.sendMessage(tabId, {
            type: 'background.getPageSnapshot',
        })) as PageSnapshotResponse | undefined;
        if (response?.ok !== true || !isPositiveVideoDurationSeconds(response.videoDurationSeconds)) {
            return {};
        }
        return {
            videoDurationSeconds: response.videoDurationSeconds,
        };
    }
    catch {
        return {};
    }
}

export async function getSubtitleStateForPage(tabId: number, pageStatus: YoutubePageInfo, expectedSessionId?: string): Promise<SubtitleState> {
    if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId))
        return DEFAULT_SUBTITLE_STATE;
    const subtitleState = runtime.tabSubtitleStates.get(tabId) ?? DEFAULT_SUBTITLE_STATE;
    if (expectedSessionId !== undefined && runtime.tabSubtitleStates.has(tabId)
        && runtime.tabSubtitleStateSessions.get(tabId) !== expectedSessionId)
        return DEFAULT_SUBTITLE_STATE;
    if (!pageStatus.supported) {
        return DEFAULT_SUBTITLE_STATE;
    }
    if (subtitleState.type !== 'no-track' && isSubtitleStateForVideo(subtitleState, pageStatus.videoId)) {
        return subtitleState;
    }
    const originalState = runtime.tabSubtitleStates.get(tabId);
    const operation = runtime.tabOperations.get(tabId);
    const mutation = runtime.tabCorrectionMutationInFlight.get(tabId);
    const rememberedTrack = await getRememberedTrack(pageStatus.videoId);
    if (!rememberedTrack) {
        return DEFAULT_SUBTITLE_STATE;
    }
    if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId))
        return DEFAULT_SUBTITLE_STATE;
    const currentTab = await browser.tabs.get(tabId).catch(() => undefined);
    const currentPage = parseYoutubePage(currentTab?.url ?? '');
    if (!currentPage.supported || currentPage.videoId !== pageStatus.videoId)
        return DEFAULT_SUBTITLE_STATE;
    if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId))
        return DEFAULT_SUBTITLE_STATE;
    const latestState = runtime.tabSubtitleStates.get(tabId);
    if (latestState !== originalState || runtime.tabOperations.get(tabId) !== operation
        || runtime.tabCorrectionMutationInFlight.get(tabId) !== mutation) {
        return latestState
            && (expectedSessionId === undefined || runtime.tabSubtitleStateSessions.get(tabId) === expectedSessionId)
            && isSubtitleStateForVideo(latestState, pageStatus.videoId) ? latestState : DEFAULT_SUBTITLE_STATE;
    }
    const restoredState: SubtitleState = { type: 'ready', track: rememberedTrack };
    runtime.tabSubtitleStates.set(tabId, restoredState);
    if (expectedSessionId !== undefined)
        runtime.tabSubtitleStateSessions.set(tabId, expectedSessionId);
    return restoredState;
}

export function isSubtitleStateForVideo(subtitleState: SubtitleState, youtubeVideoId: string): boolean {
    switch (subtitleState.type) {
        case 'loading':
            return subtitleState.youtubeVideoId === youtubeVideoId;
        case 'ready':
            return subtitleState.track.youtubeVideoId === youtubeVideoId;
        case 'error':
            return subtitleState.youtubeVideoId === youtubeVideoId;
        case 'no-track':
            return true;
    }
}

export function isCurrentLoadingState(tabId: number, youtubeVideoId: string): boolean {
    const currentState = runtime.tabSubtitleStates.get(tabId);
    return currentState?.type === 'loading' && currentState.youtubeVideoId === youtubeVideoId;
}

export async function publishSubtitleState(tabId: number, subtitleState: SubtitleState, sessionId?: string): Promise<void> {
    if (sessionId !== undefined && !await isCurrentSession(sessionId))
        return;
    runtime.tabSubtitleStates.set(tabId, subtitleState);
    if (sessionId !== undefined) {
        if (sessionId !== undefined)
            runtime.tabSubtitleStateSessions.set(tabId, sessionId);
    }
    else {
        runtime.tabSubtitleStateSessions.delete(tabId);
    }
    if (subtitleState.type === 'ready') {
        await rememberActiveTrack(subtitleState.track);
    }
    if (sessionId !== undefined && !await isCurrentSession(sessionId))
        return;
    for (const port of runtime.panelPorts) {
        try {
            port.postMessage({ type: 'background.subtitleStateChanged', tabId, subtitleState });
        }
        catch {
            runtime.panelPorts.delete(port);
        }
    }
    await sendTabMessage(tabId, {
        type: 'background.subtitleStateChanged',
        subtitleState,
    });
}

export async function getActiveTab(windowId?: number): Promise<Browser.tabs.Tab | undefined> {
    const [activeTab] = await browser.tabs.query(typeof windowId === 'number' ? { active: true, windowId } : { active: true, currentWindow: true });
    return activeTab;
}

export async function sendTabMessage(tabId: number, message: ContentRequest): Promise<void> {
    try {
        await browser.tabs.sendMessage(tabId, message);
    }
    catch {
        // Unsupported pages do not have this content script; panel state still updates locally.
    }
}

export function isPositiveVideoDurationSeconds(value: unknown): value is number {
    return typeof value === 'number' && Number.isInteger(value) && value > 0;
}

export async function isCurrentSession(sessionId: string): Promise<boolean> {
    const session = await getInstanceContext();
    return session?.sessionId === sessionId;
}

export function assertNever(value: never): never {
    throw new Error(`Unhandled background request: ${JSON.stringify(value)}`);
}

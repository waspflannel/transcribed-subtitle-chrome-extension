import { clearRememberedTracks,clearTabOperationIfMatches,clearTabOperations,getTabOperation,rememberActiveTrack,setTabOperation,updateTabOperationIfMatches } from '../active-tracks';
import { DEFAULT_BACKEND_API_BASE_URL,SubtitleApiError } from '../api';
import { loadingMessageForStage,publicSubtitleJobFailureMessage,stateWithBackendProgress,} from '../backend-subtitle-state';
import type { JobResponse,SubtitleJobHistoryItem } from '../contracts';
import { getInstanceContext,type InstanceContext } from '../instance-context';
import { DEFAULT_SUBTITLE_STATE,type PanelState } from '../messages';
import { clearLocalExtensionState,getExtensionSettings,getOrCreateInstallId,isSubtitleRecoveryBlocked,setSubtitleRecoveryBlocked,updateExtensionSettings,waitForExtensionSettingsWrites,} from '../settings';
import { type ExtensionSettings } from '../settings-model';
import { parseYoutubePage } from '../youtube';
import { resetLyricsCorrectionStatesForSession,syncLyricsCorrection } from './corrections';
import { ensureRecoveredGenerationMonitor,isCurrentGenerationRecovery,isGenerationInFlight,resolveCompletedSubtitleJob } from './generation';
import { runtime } from './runtime';
import { getActiveTab,getPageSnapshotFromTab,getSubtitleStateForPage,isCurrentSession,isSubtitleStateForVideo,publishSubtitleState,savedTrackRecoveryJobs,sendTabMessage } from './state';

export // Content scripts watch the settings storage, so every YouTube tab follows a save.
async function updateSettingsFromPanel(patch: Partial<ExtensionSettings>, windowId?: number): Promise<PanelState> {
    await updateExtensionSettings(patch);
    return getPanelState({ syncBackend: false, windowId });
}

export async function updateSettingsFromContent(patch: Partial<ExtensionSettings>): Promise<{
    ok: true;
    settings: ExtensionSettings;
}> {
    return { ok: true, settings: await updateExtensionSettings(patch) };
}

export async function clearLocalStateFromPanel(windowId?: number): Promise<PanelState> {
    const resetVersion = ++runtime.localStateResetVersion;
    await setSubtitleRecoveryBlocked(true);
    if (resetVersion !== runtime.localStateResetVersion) {
        return getPanelState({ syncBackend: false, windowId });
    }
    await waitForExtensionSettingsWrites();
    if (resetVersion !== runtime.localStateResetVersion) {
        return getPanelState({ syncBackend: false, windowId });
    }
    await clearLocalExtensionState();
    if (resetVersion !== runtime.localStateResetVersion) {
        return getPanelState({ syncBackend: false, windowId });
    }
    const session = await getInstanceContext();
    if (resetVersion !== runtime.localStateResetVersion) {
        return getPanelState({ syncBackend: false, windowId });
    }
    await clearRememberedTracks();
    if (resetVersion !== runtime.localStateResetVersion) {
        return getPanelState({ syncBackend: false, windowId });
    }
    await clearTabOperations();
    if (resetVersion !== runtime.localStateResetVersion) {
        return getPanelState({ syncBackend: false, windowId });
    }
    const resetTabIds = new Set(runtime.tabSubtitleStates.keys());
    runtime.tabSubtitleStates.clear();
    runtime.tabSubtitleStateSessions.clear();
    runtime.tabGenerationCancellationInFlight.clear();
    runtime.tabOperations.clear();
    runtime.tabGenerationInFlight.clear();
    runtime.tabCorrectionMutationInFlight.clear();
    resetLyricsCorrectionStatesForSession();
    const activeTab = await getActiveTab(windowId);
    if (resetVersion !== runtime.localStateResetVersion) {
        return getPanelState({ syncBackend: false, windowId });
    }
    const activeTabId = activeTab?.id ?? null;
    if (activeTabId !== null)
        resetTabIds.add(activeTabId);
    const settings = await getExtensionSettings();
    if (resetVersion !== runtime.localStateResetVersion) {
        return getPanelState({ syncBackend: false, windowId });
    }
    console.info('extension.local_state_cleared', {
        activeTabId,
    });
    for (const tabId of resetTabIds) {
        await sendTabMessage(tabId, {
            type: 'background.settingsChanged',
            settings,
        });
        if (resetVersion !== runtime.localStateResetVersion) {
            return getPanelState({ syncBackend: false, windowId });
        }
        await sendTabMessage(tabId, {
            type: 'background.subtitleStateChanged',
            subtitleState: DEFAULT_SUBTITLE_STATE,
        });
        if (resetVersion !== runtime.localStateResetVersion) {
            return getPanelState({ syncBackend: false, windowId });
        }
    }
    return getPanelState({ syncBackend: true, windowId });
}

export async function getPanelState(options: {
    syncBackend: boolean;
    syncLyricsCorrection?: boolean;
    windowId?: number;
    retryOnSessionChange?: boolean;
}): Promise<PanelState> {
    const activeTab = await getActiveTab(options.windowId);
    const activeTabId = activeTab?.id ?? null;
    const pageStatus = activeTab ? parseYoutubePage(activeTab.url ?? '') : undefined;
    const installId = await getOrCreateInstallId();
    const settings = await getExtensionSettings();
    let effectiveSession = await getInstanceContext();
    const startingSessionId = effectiveSession?.sessionId;
    const pageSnapshot = activeTabId === null || pageStatus?.supported !== true
        ? {}
        : await getPageSnapshotFromTab(activeTabId);
    const [, , , history] = await Promise.all([
        (async () => {
            if (options.syncBackend || !runtime.cachedInstanceSettings) {
                try {
                    runtime.cachedInstanceSettings = await runtime.subtitleApi.getInstanceSettings(installId);
                    runtime.instanceSettingsError = undefined;
                }
                catch {
                    runtime.instanceSettingsError = 'Unable to load provider settings. Check that your backend is running.';
                }
            }
        })(),
        (async () => {
            if (options.syncBackend || !runtime.cachedClaudeAccount) {
                const version = runtime.claudeAccountVersion;
                try {
                    const account = await runtime.subtitleApi.getClaudeAccount(installId);
                    if (version === runtime.claudeAccountVersion) {
                        runtime.cachedClaudeAccount = account;
                        runtime.claudeAccountError = undefined;
                    }
                }
                catch {
                    if (version === runtime.claudeAccountVersion)
                        runtime.claudeAccountError = 'Unable to load Claude. Check the backend and refresh.';
                }
            }
        })(),
        (async () => {
            if (options.syncBackend || !runtime.cachedCodexAccount) {
                const version = runtime.codexAccountVersion;
                try {
                    const account = await runtime.subtitleApi.getCodexAccount(installId);
                    if (version === runtime.codexAccountVersion) {
                        runtime.cachedCodexAccount = account;
                        runtime.codexAccountError = undefined;
                    }
                }
                catch {
                    if (version === runtime.codexAccountVersion)
                        runtime.codexAccountError = 'Unable to load Codex. Check the backend and refresh.';
                }
            }
        })(),
        getPanelJobHistory(installId, effectiveSession, options.syncBackend),
    ]);
    let backendRecoveryBlocked = await isSubtitleRecoveryBlocked();
    const localState = activeTabId === null || pageStatus === undefined
        ? DEFAULT_SUBTITLE_STATE
        : await getSubtitleStateForPage(activeTabId, pageStatus, effectiveSession?.sessionId);
    let stateForRecovery = localState;
    let createdRecoveryClaim: symbol | undefined;
    if (activeTabId !== null && pageStatus?.supported && effectiveSession) {
        let operation = await getTabOperation(activeTabId);
        if (!await isCurrentSession(effectiveSession.sessionId)) {
            return getPanelState({ ...options, retryOnSessionChange: false });
        }
        if (operation?.kind === 'generation' && operation.youtubeVideoId === pageStatus.videoId
            && !isGenerationInFlight(activeTabId, pageStatus.videoId)) {
            const recoveryOperation = operation;
            const recoveryClaim = runtime.tabOperations.get(activeTabId) ?? (createdRecoveryClaim = Symbol('panel-recovery'));
            runtime.tabOperations.set(activeTabId, recoveryClaim);
            let recoveryJob: JobResponse | undefined;
            let terminalStatusMissing = false;
            let unknownSubmission = false;
            let recoveryClaimValid = true;
            if (operation.jobId) {
                try {
                    recoveryJob = await runtime.subtitleApi.getSubtitleJob(installId, operation.jobId);
                    recoveryClaimValid = await isCurrentGenerationRecovery(activeTabId, pageStatus, effectiveSession.sessionId, recoveryClaim, recoveryOperation);
                }
                catch (error) {
                    if (error instanceof SubtitleApiError && error.code === 'not_found') {
                        terminalStatusMissing = true;
                        recoveryJob = undefined;
                        recoveryClaimValid = await isCurrentGenerationRecovery(activeTabId, pageStatus, effectiveSession.sessionId, recoveryClaim, recoveryOperation);
                        if (recoveryClaimValid) {
                            recoveryClaimValid = await clearTabOperationIfMatches(activeTabId, recoveryOperation);
                            if (recoveryClaimValid) {
                                recoveryClaimValid = await isCurrentGenerationRecovery(activeTabId, pageStatus, effectiveSession.sessionId, recoveryClaim);
                            }
                        }
                    }
                    else {
                        recoveryClaimValid = await isCurrentGenerationRecovery(activeTabId, pageStatus, effectiveSession.sessionId, recoveryClaim, recoveryOperation);
                    }
                }
            }
            else {
                const historyJob = history.jobs.find((job) => job.youtubeVideoId === pageStatus.videoId
                    && (job.status === 'queued' || job.status === 'running'));
                recoveryJob = historyJob
                    ? { ...historyJob, createdAt: historyJob.startedAt, updatedAt: historyJob.lastUpdatedAt }
                    : undefined;
                if (recoveryJob && await isCurrentGenerationRecovery(activeTabId, pageStatus, effectiveSession.sessionId, recoveryClaim, recoveryOperation)) {
                    const nextOperation = { ...operation, jobId: recoveryJob.jobId };
                    recoveryClaimValid = await updateTabOperationIfMatches(activeTabId, operation, nextOperation);
                    if (recoveryClaimValid)
                        operation = nextOperation;
                }
                else if (!recoveryJob && !history.error && localState.type !== 'ready'
                    && !isGenerationInFlight(activeTabId, pageStatus.videoId)) {
                    unknownSubmission = true;
                    recoveryClaimValid = await isCurrentGenerationRecovery(activeTabId, pageStatus, effectiveSession.sessionId, recoveryClaim, recoveryOperation);
                    if (recoveryClaimValid) {
                        recoveryClaimValid = await clearTabOperationIfMatches(activeTabId, recoveryOperation);
                        if (recoveryClaimValid) {
                            recoveryClaimValid = await isCurrentGenerationRecovery(activeTabId, pageStatus, effectiveSession.sessionId, recoveryClaim);
                        }
                    }
                }
            }
            if (!recoveryJob && (terminalStatusMissing || unknownSubmission) && recoveryClaimValid) {
                stateForRecovery = {
                    type: 'error',
                    ...(operation.jobId ? { jobId: operation.jobId } : {}),
                    youtubeVideoId: pageStatus.videoId,
                    message: terminalStatusMissing
                        ? 'This generation is no longer available. Start a new generation if needed.'
                        : 'Generation status is unavailable. Try Generate again.',
                };
                runtime.tabSubtitleStates.set(activeTabId, stateForRecovery);
                runtime.tabSubtitleStateSessions.set(activeTabId, effectiveSession.sessionId);
                runtime.tabOperations.delete(activeTabId);
                runtime.tabGenerationInFlight.delete(activeTabId);
            }
            else if (!recoveryJob && localState.type !== 'ready') {
                stateForRecovery = {
                    type: 'loading',
                    status: localState.type === 'loading' ? localState.status : 'running',
                    ...(operation.jobId ? { jobId: operation.jobId } : {}),
                    youtubeVideoId: pageStatus.videoId,
                    youtubeUrl: pageStatus.url,
                    message: localState.type === 'loading' ? localState.message : 'Preparing request...',
                    stage: localState.type === 'loading' ? localState.stage : 'preparing',
                    progressPercent: localState.type === 'loading' ? localState.progressPercent : 5,
                    ...(localState.type === 'loading' && localState.startedAt ? { startedAt: localState.startedAt } : {}),
                    ...(localState.type === 'loading' && localState.lastUpdatedAt ? { lastUpdatedAt: localState.lastUpdatedAt } : {}),
                    ...(operation.partialTrack ? { partialTrack: operation.partialTrack } : {}),
                };
                if (operation.jobId && !terminalStatusMissing && recoveryClaimValid
                    && await isCurrentGenerationRecovery(activeTabId, pageStatus, effectiveSession.sessionId, recoveryClaim, operation)) {
                    ensureRecoveredGenerationMonitor(activeTabId, pageStatus, installId, effectiveSession);
                }
            }
            else if (!recoveryJob) {
                // A ready state belongs to the current tab but has no matching pending
                // operation; preserve it rather than clearing a newer track.
                stateForRecovery = localState;
            }
            else if (recoveryJob.status === 'queued' || recoveryJob.status === 'running') {
                stateForRecovery = {
                    type: 'loading',
                    status: recoveryJob.status,
                    jobId: recoveryJob.jobId,
                    youtubeVideoId: pageStatus.videoId,
                    youtubeUrl: pageStatus.url,
                    message: recoveryJob.status === 'queued' ? 'Queued - waiting for a generation slot...' : loadingMessageForStage(recoveryJob.stage),
                    stage: recoveryJob.stage,
                    progressPercent: recoveryJob.progressPercent,
                    startedAt: recoveryJob.createdAt,
                    lastUpdatedAt: recoveryJob.updatedAt,
                    ...(operation.partialTrack ? { partialTrack: operation.partialTrack } : {}),
                };
                if (recoveryClaimValid && await isCurrentGenerationRecovery(activeTabId, pageStatus, effectiveSession.sessionId, recoveryClaim, operation)) {
                    runtime.tabSubtitleStates.set(activeTabId, stateForRecovery);
                    runtime.tabSubtitleStateSessions.set(activeTabId, effectiveSession.sessionId);
                    ensureRecoveredGenerationMonitor(activeTabId, pageStatus, installId, effectiveSession, recoveryJob);
                }
            }
            else if (recoveryJob.status === 'completed' && recoveryJob.track) {
                stateForRecovery = { type: 'ready', track: recoveryJob.track };
                if (recoveryClaimValid && await isCurrentGenerationRecovery(activeTabId, pageStatus, effectiveSession.sessionId, recoveryClaim, operation)) {
                    runtime.tabSubtitleStates.set(activeTabId, stateForRecovery);
                    runtime.tabSubtitleStateSessions.set(activeTabId, effectiveSession.sessionId);
                    if (await isCurrentSession(effectiveSession.sessionId)) {
                        await rememberActiveTrack(recoveryJob.track);
                    }
                    if (await isCurrentGenerationRecovery(activeTabId, pageStatus, effectiveSession.sessionId, recoveryClaim, operation)) {
                        await clearTabOperationIfMatches(activeTabId, operation);
                    }
                }
            }
            else if (recoveryJob.status === 'cancelled') {
                stateForRecovery = DEFAULT_SUBTITLE_STATE;
                if (recoveryClaimValid && await isCurrentGenerationRecovery(activeTabId, pageStatus, effectiveSession.sessionId, recoveryClaim, operation)) {
                    const cleared = await clearTabOperationIfMatches(activeTabId, operation);
                    if (cleared && await isCurrentGenerationRecovery(activeTabId, pageStatus, effectiveSession.sessionId, recoveryClaim)) {
                        runtime.tabSubtitleStates.delete(activeTabId);
                        runtime.tabSubtitleStateSessions.delete(activeTabId);
                        runtime.tabOperations.delete(activeTabId);
                        runtime.tabGenerationInFlight.delete(activeTabId);
                    }
                }
            }
            else {
                stateForRecovery = {
                    type: 'error',
                    jobId: recoveryJob.jobId,
                    youtubeVideoId: pageStatus.videoId,
                    message: publicSubtitleJobFailureMessage(recoveryJob),
                };
                if (recoveryClaimValid && await isCurrentGenerationRecovery(activeTabId, pageStatus, effectiveSession.sessionId, recoveryClaim, operation)) {
                    const cleared = await clearTabOperationIfMatches(activeTabId, operation);
                    if (cleared && await isCurrentGenerationRecovery(activeTabId, pageStatus, effectiveSession.sessionId, recoveryClaim)) {
                        runtime.tabSubtitleStates.set(activeTabId, stateForRecovery);
                        runtime.tabSubtitleStateSessions.set(activeTabId, effectiveSession.sessionId);
                        runtime.tabOperations.delete(activeTabId);
                        runtime.tabGenerationInFlight.delete(activeTabId);
                    }
                }
            }
        }
        if (!backendRecoveryBlocked && !operation && localState.type !== 'ready' && localState.type !== 'error') {
            const historyJob = history.jobs.find((job) => job.youtubeVideoId === pageStatus.videoId
                && (job.status === 'queued' || job.status === 'running'));
            if (historyJob && await isCurrentSession(effectiveSession.sessionId)) {
                const recoveredOperation = {
                    kind: 'generation' as const,
                    youtubeVideoId: pageStatus.videoId,
                    jobId: historyJob.jobId,
                };
                const recoveredClaim = runtime.tabOperations.get(activeTabId) ?? (createdRecoveryClaim = Symbol('panel-history-recovery'));
                runtime.tabOperations.set(activeTabId, recoveredClaim);
                await setTabOperation(activeTabId, recoveredOperation);
                if (await isCurrentGenerationRecovery(activeTabId, pageStatus, effectiveSession.sessionId, recoveredClaim, recoveredOperation) && !isGenerationInFlight(activeTabId, pageStatus.videoId)) {
                    runtime.tabSubtitleStates.set(activeTabId, {
                        type: 'loading',
                        status: historyJob.status === 'queued' ? 'queued' : 'running',
                        jobId: historyJob.jobId,
                        youtubeVideoId: pageStatus.videoId,
                        youtubeUrl: pageStatus.url,
                        message: historyJob.status === 'queued' ? 'Queued - waiting for a generation slot...' : loadingMessageForStage(historyJob.stage),
                        stage: historyJob.stage,
                        progressPercent: historyJob.progressPercent,
                        startedAt: historyJob.startedAt,
                        lastUpdatedAt: historyJob.lastUpdatedAt,
                    });
                    runtime.tabSubtitleStateSessions.set(activeTabId, effectiveSession.sessionId);
                    ensureRecoveredGenerationMonitor(activeTabId, pageStatus, installId, effectiveSession, {
                        ...historyJob,
                        createdAt: historyJob.startedAt,
                        updatedAt: historyJob.lastUpdatedAt,
                    });
                }
            }
        }
    }
    // A recovery claim that no monitor took over would stay until the tab
    // closes and slow every status poll loop through minimumActivePollInterval.
    if (activeTabId !== null && createdRecoveryClaim && runtime.tabOperations.get(activeTabId) === createdRecoveryClaim) {
        runtime.tabOperations.delete(activeTabId);
    }
    backendRecoveryBlocked = await isSubtitleRecoveryBlocked();
    const recoveryJobs = !backendRecoveryBlocked && options.syncBackend && effectiveSession && pageStatus?.supported
        ? await savedTrackRecoveryJobs(stateForRecovery, pageStatus, history.jobs, installId, effectiveSession)
        : history.jobs;
    let subtitleState = backendRecoveryBlocked || !options.syncBackend
        || (activeTabId !== null && pageStatus?.supported === true && isGenerationInFlight(activeTabId, pageStatus.videoId))
        ? stateForRecovery : await stateWithBackendProgress(stateForRecovery, pageStatus, recoveryJobs, (job) => effectiveSession ? resolveCompletedSubtitleJob(installId, job, effectiveSession.sessionId) : Promise.resolve(null));
    const lyricsCorrection = await syncLyricsCorrection(activeTabId, pageStatus, effectiveSession, installId, options.syncBackend || options.syncLyricsCorrection === true, stateForRecovery);
    if (activeTabId !== null && pageStatus?.supported) {
        const currentSubtitleState = effectiveSession === null
            ? undefined
            : runtime.tabSubtitleStateSessions.get(activeTabId) === effectiveSession.sessionId
                ? runtime.tabSubtitleStates.get(activeTabId)
                : undefined;
        if (currentSubtitleState && currentSubtitleState !== localState && isSubtitleStateForVideo(currentSubtitleState, pageStatus.videoId)) {
            subtitleState = currentSubtitleState;
        }
    }
    if (activeTabId !== null && pageStatus && subtitleState.type === 'ready' && localState.type !== 'ready'
        && (!effectiveSession || await isCurrentSession(effectiveSession.sessionId))) {
        if (await isSubtitleRecoveryBlocked()) {
            subtitleState = await getSubtitleStateForPage(activeTabId, pageStatus, effectiveSession?.sessionId);
        }
        else {
            await publishSubtitleState(activeTabId, subtitleState, effectiveSession?.sessionId);
        }
    }
    const currentSession = await getInstanceContext();
    const latestActiveTab = await getActiveTab(options.windowId);
    const latestPageStatus = latestActiveTab ? parseYoutubePage(latestActiveTab.url ?? '') : undefined;
    const pageChanged = latestActiveTab?.id !== activeTabId
        || (pageStatus?.supported === true && (!latestPageStatus?.supported || latestPageStatus.videoId !== pageStatus.videoId));
    if (options.retryOnSessionChange !== false && (startingSessionId !== currentSession?.sessionId || pageChanged)) {
        return getPanelState({ ...options, retryOnSessionChange: false });
    }
    if (startingSessionId !== currentSession?.sessionId || pageChanged) {
        // The bounded retry also raced a session or navigation change. Return a
        // redacted current snapshot instead of pairing old history/state with it.
        return {
            installId,
            settings,
            activeTabId: latestActiveTab?.id,
            pageStatus: latestPageStatus,
            pageTitle: latestActiveTab?.title,
            backendUrl: DEFAULT_BACKEND_API_BASE_URL,
            instanceSettings: runtime.cachedInstanceSettings,
            instanceSettingsError: runtime.instanceSettingsError,
            codexAccount: runtime.cachedCodexAccount,
            codexAccountError: runtime.codexAccountError,
            claudeAccount: runtime.cachedClaudeAccount,
            claudeAccountError: runtime.claudeAccountError,
            subtitleState: DEFAULT_SUBTITLE_STATE,
            jobHistory: [],
            lyricsCorrection: null,
        };
    }
    return {
        installId,
        settings,
        activeTabId: activeTabId ?? undefined,
        pageStatus,
        pageTitle: activeTab?.title,
        pageVideoDurationSeconds: pageSnapshot.videoDurationSeconds,
        backendUrl: DEFAULT_BACKEND_API_BASE_URL,
        instanceSettings: runtime.cachedInstanceSettings,
        instanceSettingsError: runtime.instanceSettingsError,
        codexAccount: runtime.cachedCodexAccount,
        codexAccountError: runtime.codexAccountError,
        claudeAccount: runtime.cachedClaudeAccount,
        claudeAccountError: runtime.claudeAccountError,
        subtitleState,
        jobHistory: history.jobs,
        jobHistoryError: history.error,
        lyricsCorrection,
        lyricsCorrectionSyncError: activeTabId !== null ? runtime.tabLyricsCorrectionStates.get(activeTabId)?.syncError : undefined,
    };
}

export async function getPanelJobHistory(installId: string, session: InstanceContext | null, syncBackend: boolean): Promise<{
    jobs: SubtitleJobHistoryItem[];
    error?: string;
}> {
    if (!session)
        return { jobs: [] };
    const matches = runtime.cachedPanelJobHistorySessionId === session.sessionId;
    if (!syncBackend)
        return matches ? { jobs: runtime.cachedPanelJobHistory, error: runtime.cachedPanelJobHistoryError } : { jobs: [] };
    try {
        const response = await runtime.subtitleApi.listSubtitleJobs(installId);
        if (!await isCurrentSession(session.sessionId))
            return { jobs: [] };
        runtime.cachedPanelJobHistory = response.jobs;
        runtime.cachedPanelJobHistoryError = undefined;
        runtime.cachedPanelJobHistorySessionId = session.sessionId;
    }
    catch {
        runtime.cachedPanelJobHistoryError = 'Unable to load backend job history.';
    }
    return { jobs: matches || runtime.cachedPanelJobHistorySessionId === session.sessionId ? runtime.cachedPanelJobHistory : [], error: runtime.cachedPanelJobHistoryError };
}

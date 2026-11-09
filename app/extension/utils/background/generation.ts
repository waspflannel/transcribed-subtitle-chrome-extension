import { browser } from 'wxt/browser';
import { clearTabOperationIfMatches,forgetRememberedTrack,getTabOperation,rememberActiveTrack,setTabOperation,updateTabOperationIfMatches,type StoredTabOperation } from '../active-tracks';
import { publicSubtitleErrorMessage,SubtitleApiError,SubtitleContractError } from '../api';
import { loadingMessageForStage,publicSubtitleJobFailureMessage } from '../backend-subtitle-state';
import type { CreateSubtitleJobRequest,JobResponse,SubtitleJobHistoryItem } from '../contracts';
import { getInstanceContext,type InstanceContext } from '../instance-context';
import { DEFAULT_SUBTITLE_STATE,type BackgroundRequest,type PageSnapshot,type PanelState,type PartialSubtitleTrack,type SubtitleState } from '../messages';
import { getExtensionSettings,getOrCreateInstallId,setSubtitleRecoveryBlocked,waitForExtensionSettingsWrites } from '../settings';
import { CLAUDE_FAST_MODELS,type ExtensionSettings } from '../settings-model';
import { parseYoutubePage } from '../youtube';
import { activeReadyTrackMatches,tombstoneLyricsCorrectionState } from './corrections';
import { getPanelState } from './panel';
import { runtime,type SupportedYoutubePageInfo } from './runtime';
import { getActiveTab,getPageSnapshotFromTab,getSubtitleStateForPage,isCurrentLoadingState,isCurrentSession,isPositiveVideoDurationSeconds,publishSubtitleState,recoverSubtitleStateFromBackend,sendTabMessage } from './state';

export // A tab can move to another video while an earlier generation still polls;
// that generation must not block edits or generation for the new video.
function isGenerationInFlight(tabId: number, youtubeVideoId: string): boolean {
    return runtime.tabGenerationInFlight.get(tabId) === youtubeVideoId;
}

export function isGenerationCancellationClaim(tabId: number, jobId: string, operation: symbol): boolean {
    const claim = runtime.tabGenerationCancellationInFlight.get(tabId);
    return claim?.jobId === jobId && claim.operation === operation;
}

export async function generateSubtitlesFromPanel(youtubeVideoId: string, tabId: number, windowId?: number): Promise<PanelState> {
    const generationResetVersion = runtime.localStateResetVersion;
    const activeTab = await getActiveTab(windowId);
    const activeTabId = activeTab?.id ?? null;
    if (activeTabId === null) {
        throw new Error('Open a supported YouTube video or Short before generating subtitles.');
    }
    const pageStatus = parseYoutubePage(activeTab?.url ?? '');
    if (!pageStatus.supported) {
        const subtitleState: SubtitleState = {
            type: 'error',
            message: 'Open a supported YouTube video or Short before generating subtitles.',
        };
        await publishSubtitleState(activeTabId, subtitleState);
        return getPanelState({ syncBackend: true, windowId });
    }
    if (isGenerationInFlight(activeTabId, pageStatus.videoId) || runtime.tabCorrectionMutationInFlight.has(activeTabId)) {
        throw new SubtitleApiError('lyrics_correction_in_progress', 'A subtitle edit is already in progress.', 409);
    }
    if (activeTabId !== tabId || pageStatus.videoId !== youtubeVideoId)
        throw new Error('The selected video changed. Try again.');
    const operation = Symbol('generation');
    runtime.tabOperations.set(activeTabId, operation);
    runtime.tabGenerationCancellationInFlight.delete(activeTabId);
    runtime.tabGenerationInFlight.set(activeTabId, pageStatus.videoId);
    let generationStarted = false;
    try {
        const session = await getInstanceContext();
        const persistedOperation = await getTabOperation(activeTabId);
        if (session?.sessionId !== (await getInstanceContext())?.sessionId) {
            return getPanelState({ syncBackend: false, windowId });
        }
        if (persistedOperation?.youtubeVideoId === pageStatus.videoId && persistedOperation.jobId) {
            if (persistedOperation.kind === 'correction') {
                const correction = await runtime.subtitleApi.getLyricsCorrectionStatus(await getOrCreateInstallId(), persistedOperation.jobId);
                if (correction.status === 'queued' || correction.status === 'running') {
                    throw new SubtitleApiError('lyrics_correction_in_progress', 'A subtitle edit is already in progress.', 409);
                }
            }
            else {
                const job = await runtime.subtitleApi.getSubtitleJob(await getOrCreateInstallId(), persistedOperation.jobId);
                if (job.status === 'queued' || job.status === 'running') {
                    throw new SubtitleApiError('lyrics_correction_in_progress', 'Subtitle generation is already in progress.', 409);
                }
            }
            await clearTabOperationIfMatches(activeTabId, persistedOperation);
        }
        else if (persistedOperation) {
            await clearTabOperationIfMatches(activeTabId, persistedOperation);
        }
        const currentState = await getSubtitleStateForPage(activeTabId, pageStatus, session?.sessionId);
        if (currentState.type === 'ready' && session) {
            try {
                const correction = await runtime.subtitleApi.getLyricsCorrectionStatus(await getOrCreateInstallId(), currentState.track.jobId);
                if (correction.status === 'queued' || correction.status === 'running') {
                    throw new SubtitleApiError('lyrics_correction_in_progress', 'A subtitle edit is already in progress.', 409);
                }
            }
            catch (error) {
                if (!(error instanceof SubtitleApiError) || error.code !== 'not_found') {
                    throw error;
                }
            }
        }
        if (currentState.type !== 'loading') {
            await waitForExtensionSettingsWrites();
            if (!await isCurrentSession(session.sessionId)) {
                throw new Error('The local state changed. Try again.');
            }
            const settings = await getExtensionSettings();
            const providers = (await runtime.subtitleApi.getInstanceSettings(await getOrCreateInstallId())).providers;
            if (!providers.elevenlabs.configured) {
                throw new SubtitleApiError('provider_not_configured', 'Add the required provider keys in Settings before generating subtitles.', 422);
            }
            if (settings.aiProvider === 'codex') {
                const account = await runtime.subtitleApi.getCodexAccount(await getOrCreateInstallId());
                if (!account.available || !account.connected)
                    throw new Error('Connect Codex in Settings before generating subtitles.');
                const model = account.models.find(model => model.id === settings.codexModel);
                if (!model)
                    throw new Error('Select an available Codex model before generating subtitles.');
                if (settings.codexFastMode && !model.supportsFastMode)
                    throw new Error('Fast mode is unavailable for this Codex model.');
            }
            else if (settings.aiProvider === 'claude') {
                if (!providers.claude.available)
                    throw new Error('Install Claude Code CLI 2.1.280 or newer on the backend.');
            }
            else if (!providers[settings.aiProvider].configured) {
                throw new SubtitleApiError('provider_not_configured', 'Add the required provider keys in Settings before generating subtitles.', 422);
            }
            const pageSnapshot = await getPageSnapshotFromTab(activeTabId);
            const now = new Date().toISOString();
            if (!await isCurrentSession(session.sessionId)) {
                throw new Error('The local state changed. Try again.');
            }
            if (generationResetVersion !== runtime.localStateResetVersion) {
                return getPanelState({ syncBackend: false, windowId });
            }
            await setSubtitleRecoveryBlocked(false);
            if (generationResetVersion !== runtime.localStateResetVersion) {
                return getPanelState({ syncBackend: false, windowId });
            }
            await publishSubtitleState(activeTabId, {
                type: 'loading',
                status: 'running',
                youtubeVideoId: pageStatus.videoId,
                youtubeUrl: pageStatus.url,
                message: 'Preparing request...',
                stage: 'preparing',
                progressPercent: 5,
                startedAt: now,
                lastUpdatedAt: now,
            }, session.sessionId);
            if (!await isCurrentSession(session.sessionId)) {
                throw new Error('The local state changed. Try again.');
            }
            await setTabOperation(activeTabId, { kind: 'generation', youtubeVideoId: pageStatus.videoId });
            generationStarted = true;
            void generateSubtitlesForTab(activeTabId, pageStatus, settings, pageSnapshot, session, operation, currentState.type === 'ready', windowId)
                .finally(() => {
                if (runtime.tabOperations.get(activeTabId) !== operation)
                    return;
                runtime.tabGenerationInFlight.delete(activeTabId);
                // Cancellation still needs its claim to clear the displayed state.
                if (runtime.tabGenerationCancellationInFlight.get(activeTabId)?.operation !== operation) {
                    runtime.tabOperations.delete(activeTabId);
                }
            });
        }
        return getPanelState({ syncBackend: false, windowId });
    }
    finally {
        if (!generationStarted) {
            if (runtime.tabOperations.get(activeTabId) === operation) {
                runtime.tabOperations.delete(activeTabId);
                runtime.tabGenerationInFlight.delete(activeTabId);
            }
        }
    }
}

export async function generateSubtitlesForTab(tabId: number, pageStatus: SupportedYoutubePageInfo, settings: ExtensionSettings, pageSnapshot: PageSnapshot, session: InstanceContext, operation: symbol, forceRegenerate: boolean, windowId?: number): Promise<void> {
    const sessionId = session.sessionId;
    let retainOperation = false;
    try {
        if (runtime.tabOperations.get(tabId) !== operation || !await isCurrentSession(sessionId))
            return;
        console.info('extension.subtitle_generation_started', {
            youtubeVideoId: pageStatus.videoId,
            sourceLanguage: settings.sourceLanguage,
            targetLanguage: settings.targetLanguage,
            aiProvider: settings.aiProvider,
            ...aiSelectionPayload(settings),
            includeRomanization: settings.showRomanization,
            includeTranslation: settings.showTranslation,
        });
        const installId = await getOrCreateInstallId();
        // Keep the request tied to its video and session after asynchronous preparation.
        const currentSession = await getInstanceContext();
        const currentTab = await getActiveTab(windowId);
        const currentPage = parseYoutubePage(currentTab?.url ?? '');
        if (runtime.tabOperations.get(tabId) !== operation || currentSession?.sessionId !== sessionId)
            return;
        if (currentTab?.id !== tabId || !currentPage.supported
            || currentPage.videoId !== pageStatus.videoId) {
            throw new Error('The selected video changed. Open generation for the current video.');
        }
        const initialJob = await runtime.subtitleApi.createSubtitleJob(installId, {
            ...(forceRegenerate ? { forceRegenerate: true } : {}),
            youtubeVideoId: pageStatus.videoId,
            youtubeUrl: pageStatus.url,
            ...(isPositiveVideoDurationSeconds(pageSnapshot.videoDurationSeconds)
                ? { videoDurationSeconds: pageSnapshot.videoDurationSeconds }
                : {}),
            sourceLanguage: settings.sourceLanguage,
            targetLanguage: settings.targetLanguage,
            aiProvider: settings.aiProvider,
            ...aiSelectionPayload(settings),
            includeRomanization: settings.showRomanization,
            includeTranslation: settings.showTranslation,
        });
        if (runtime.tabOperations.get(tabId) !== operation || isGenerationCancellationClaim(tabId, initialJob.jobId, operation)
            || !await isCurrentSession(sessionId))
            return;
        const current = runtime.tabSubtitleStates.get(tabId);
        if (current?.type !== 'loading')
            return;
        runtime.tabSubtitleStates.set(tabId, { ...current, jobId: initialJob.jobId });
        runtime.tabSubtitleStateSessions.set(tabId, sessionId);
        await setTabOperation(tabId, { kind: 'generation', youtubeVideoId: pageStatus.videoId, jobId: initialJob.jobId });
        if (runtime.tabOperations.get(tabId) !== operation || !await isCurrentSession(sessionId))
            return;
        const job = await waitForCompletedSubtitleJob(tabId, pageStatus, installId, session, initialJob, operation);
        if (job === null) {
            retainOperation = true;
            return;
        }
        if (job.status === 'cancelled') {
            await clearCancelledGenerationState(tabId, job.jobId, pageStatus.videoId, sessionId, operation);
            return;
        }
        if (isGenerationCancellationClaim(tabId, job.jobId, operation)) {
            return;
        }
        if (job.status === 'failed') {
            console.warn('extension.subtitle_generation_failed', {
                youtubeVideoId: pageStatus.videoId,
                jobId: job.jobId,
                backendMessage: job.message,
            });
            if (runtime.tabOperations.get(tabId) === operation && isCurrentLoadingState(tabId, pageStatus.videoId)) {
                await publishSubtitleState(tabId, {
                    type: 'error',
                    jobId: job.jobId,
                    youtubeVideoId: pageStatus.videoId,
                    message: publicSubtitleJobFailureMessage(job),
                }, sessionId);
            }
            return;
        }
        if (!job.track) {
            throw new Error('Completed subtitle job did not include a track.');
        }
        if (runtime.tabOperations.get(tabId) === operation
            && !isGenerationCancellationClaim(tabId, job.jobId, operation)
            && await isCurrentSession(sessionId)) {
            if (isCurrentLoadingState(tabId, pageStatus.videoId)) {
                await publishSubtitleState(tabId, {
                    type: 'ready',
                    track: job.track,
                }, sessionId);
            }
            else {
                await rememberActiveTrack(job.track);
            }
        }
        console.info('extension.subtitle_generation_completed', {
            youtubeVideoId: pageStatus.videoId,
            jobId: job.jobId,
            trackId: job.track.trackId,
        });
    }
    catch (error) {
        console.warn('extension.subtitle_generation_failed', {
            youtubeVideoId: pageStatus.videoId,
            errorCode: error instanceof SubtitleApiError ? error.code : 'extension_error',
            status: error instanceof SubtitleApiError ? error.status : undefined,
        });
        if (await isCurrentSession(sessionId)
            && runtime.tabOperations.get(tabId) === operation && isCurrentLoadingState(tabId, pageStatus.videoId)) {
            await publishSubtitleState(tabId, {
                type: 'error',
                youtubeVideoId: pageStatus.videoId,
                message: publicSubtitleErrorMessage(error, settings.aiProvider),
            }, sessionId);
        }
    }
    finally {
        const persistedOperation = await getTabOperation(tabId);
        if (!retainOperation && await isCurrentSession(sessionId) && runtime.tabOperations.get(tabId) === operation && persistedOperation?.kind === 'generation'
            && persistedOperation.youtubeVideoId === pageStatus.videoId) {
            await clearTabOperationIfMatches(tabId, persistedOperation);
        }
    }
}

export // Chunked transcription may publish a stable draft before analysis starts.
async function waitForCompletedSubtitleJob(tabId: number, pageStatus: SupportedYoutubePageInfo, installId: string, session: InstanceContext, initialJob: JobResponse, operation: symbol): Promise<JobResponse | null> {
    let job = initialJob;
    let stream = runtime.subtitleApi.streamSubtitleJob(installId, job.jobId);
    let streaming = true;
    let failures = 0;
    let publishedVersion: string | undefined;
    let partialTrack = (await getTabOperation(tabId))?.partialTrack;
    let jobPollInterval = job.status === 'queued' ? runtime.JOB_POLL_INTERVAL_MS : runtime.ACTIVE_JOB_POLL_INTERVAL_MS;
    try {
        while (runtime.tabOperations.get(tabId) === operation) {
            if (isGenerationCancellationClaim(tabId, job.jobId, operation))
                return null;
            if (job.status === 'completed' || job.status === 'failed') {
                return job;
            }
            if (job.status === 'cancelled') {
                return job;
            }
            const currentSession = await getInstanceContext();
            if (!currentSession || currentSession.sessionId !== session.sessionId) {
                return null;
            }
            let partialChanged = false;
            if (job.partialTrack) {
                const refreshedTrack: PartialSubtitleTrack = {
                    ...job.partialTrack,
                    sourceLanguage: job.detectedSourceLanguage ?? job.sourceLanguage,
                    targetLanguage: job.targetLanguage,
                };
                const revisionChanged = refreshedTrack.revision !== partialTrack?.revision;
                if (revisionChanged && runtime.tabOperations.get(tabId) === operation) {
                    jobPollInterval = runtime.ACTIVE_JOB_POLL_INTERVAL_MS;
                    partialTrack = refreshedTrack;
                    partialChanged = true;
                }
            }
            if (runtime.tabOperations.get(tabId) !== operation)
                return null;
            if (isGenerationCancellationClaim(tabId, job.jobId, operation))
                return null;
            const version = JSON.stringify([job.status, job.stage, job.progressPercent, partialTrack?.revision]);
            if (version !== publishedVersion && await isCurrentSession(session.sessionId) && isCurrentLoadingState(tabId, pageStatus.videoId)) {
                publishedVersion = version;
                await publishSubtitleState(tabId, {
                    type: 'loading',
                    status: job.status,
                    jobId: job.jobId,
                    youtubeVideoId: pageStatus.videoId,
                    youtubeUrl: pageStatus.url,
                    message: partialTrack?.readyThroughMs
                        ? `Subtitles ready through ${Math.floor(partialTrack.readyThroughMs / 60000)}:${String(Math.floor(partialTrack.readyThroughMs / 1000) % 60).padStart(2, '0')}. Preparing the rest...`
                        : job.status === 'queued' ? 'Queued - waiting for a generation slot...' : loadingMessageForStage(job.stage),
                    stage: job.stage,
                    progressPercent: job.progressPercent,
                    startedAt: job.createdAt,
                    lastUpdatedAt: job.updatedAt,
                    ...(partialTrack ? { partialTrack } : {}),
                }, session.sessionId);
            }
            if (partialChanged) {
                if (!await isCurrentSession(session.sessionId))
                    return null;
                const persistedOperation = await getTabOperation(tabId);
                if (runtime.tabOperations.get(tabId) === operation && persistedOperation?.kind === 'generation'
                    && persistedOperation.youtubeVideoId === pageStatus.videoId
                    && persistedOperation.jobId === job.jobId) {
                    await updateTabOperationIfMatches(tabId, persistedOperation, { ...persistedOperation, partialTrack });
                }
            }
            await delay(Math.max(jobPollInterval, minimumActivePollInterval()));
            if (runtime.tabOperations.get(tabId) !== operation || isGenerationCancellationClaim(tabId, job.jobId, operation))
                return null;
            const refreshedSession = await getInstanceContext();
            if (!refreshedSession || refreshedSession.sessionId !== session.sessionId)
                return null;
            try {
                let pushed: JobResponse | undefined;
                if (streaming) {
                    try {
                        const next = await stream.next();
                        if (next.done)
                            stream = runtime.subtitleApi.streamSubtitleJob(installId, job.jobId);
                        else
                            pushed = next.value;
                    }
                    catch (error) {
                        if (error instanceof SubtitleContractError)
                            throw error;
                        streaming = false;
                    }
                }
                const refreshedJob = pushed ?? await runtime.subtitleApi.getSubtitleJob(installId, job.jobId, partialTrack?.revision);
                failures = 0;
                jobPollInterval = refreshedJob.status === 'queued' ? runtime.JOB_POLL_INTERVAL_MS : runtime.ACTIVE_JOB_POLL_INTERVAL_MS;
                job = refreshedJob;
            }
            catch (error) {
                jobPollInterval = 5000;
                publishedVersion = undefined;
                if (error instanceof SubtitleContractError
                    || (error instanceof SubtitleApiError && error.status < 500 && error.status !== 429)
                    || ++failures >= runtime.JOB_POLL_MAX_FAILURES) {
                    throw error;
                }
                if (isCurrentLoadingState(tabId, pageStatus.videoId)) {
                    await publishSubtitleState(tabId, {
                        type: 'loading',
                        status: job.status === 'queued' ? 'queued' : 'running',
                        jobId: job.jobId,
                        youtubeVideoId: pageStatus.videoId,
                        youtubeUrl: pageStatus.url,
                        message: 'Reconnecting to generation status...',
                        stage: job.stage,
                        progressPercent: job.progressPercent,
                        startedAt: job.createdAt,
                        lastUpdatedAt: new Date().toISOString(),
                        ...(partialTrack ? { partialTrack } : {}),
                    }, session.sessionId);
                }
                continue;
            }
            if (job.jobId !== initialJob.jobId || job.youtubeVideoId !== pageStatus.videoId) {
                throw new SubtitleContractError('Subtitle status did not match the requested job. Update the extension and backend, then try again.');
            }
        }
        return null;
    }
    finally {
        await stream.return(undefined);
    }
}

export function minimumActivePollInterval(): number {
    // One status request per second across active tabs leaves rate-limit headroom.
    return runtime.ACTIVE_JOB_POLL_INTERVAL_MS * Math.max(1, runtime.tabOperations.size);
}

export function delay(milliseconds: number): Promise<void> {
    return new Promise((resolve) => {
        globalThis.setTimeout(resolve, milliseconds);
    });
}

export async function clearCancelledGenerationState(tabId: number, jobId: string, youtubeVideoId: string, sessionId: string, operation: symbol): Promise<void> {
    if (!await isCurrentSession(sessionId))
        return;
    let currentOperation = await getTabOperation(tabId);
    if (!await isCurrentSession(sessionId) || runtime.tabOperations.get(tabId) !== operation)
        return;
    const operationMatches = currentOperation === null || (currentOperation.kind === 'generation'
        && currentOperation.youtubeVideoId === youtubeVideoId
        && currentOperation.jobId === jobId);
    const currentState = runtime.tabSubtitleStates.get(tabId);
    const stateMatches = isCancelledGenerationState(currentState, tabId, youtubeVideoId, jobId, sessionId);
    // A missing persisted operation is safe to recover when the in-memory state
    // still names this cancelled job. A different persisted operation belongs to
    // a newer request and must stop the cleanup.
    if (!operationMatches)
        return;
    if (stateMatches && currentState?.type === 'ready') {
        if (!await isCurrentSession(sessionId))
            return;
        await forgetRememberedTrack(youtubeVideoId, currentState.track.trackId);
    }
    if (!await isCurrentSession(sessionId) || runtime.tabOperations.get(tabId) !== operation)
        return;
    currentOperation = await getTabOperation(tabId);
    if (currentOperation && !(currentOperation.kind === 'generation'
        && currentOperation.youtubeVideoId === youtubeVideoId
        && currentOperation.jobId === jobId))
        return;
    if (currentOperation && !await clearTabOperationIfMatches(tabId, currentOperation))
        return;
    // All storage/browser awaits are complete. Only now inspect the live claim
    // and state, so a newer same-tab operation cannot be deleted by this one.
    const finalOperation = await getTabOperation(tabId);
    const finalSession = await getInstanceContext();
    const ownsClaim = runtime.tabOperations.get(tabId) === operation;
    const latestState = runtime.tabSubtitleStates.get(tabId);
    const stateStillMatches = isCancelledGenerationState(latestState, tabId, youtubeVideoId, jobId, sessionId);
    if (finalSession?.sessionId !== sessionId || finalOperation !== null || !ownsClaim)
        return;
    runtime.tabOperations.delete(tabId);
    runtime.tabGenerationInFlight.delete(tabId);
    if (isGenerationCancellationClaim(tabId, jobId, operation)) {
        runtime.tabGenerationCancellationInFlight.delete(tabId);
    }
    if (stateStillMatches) {
        runtime.tabSubtitleStates.delete(tabId);
        runtime.tabSubtitleStateSessions.delete(tabId);
        await sendTabMessage(tabId, {
            type: 'background.subtitleStateChanged',
            subtitleState: DEFAULT_SUBTITLE_STATE,
        });
    }
}

export function isCancelledGenerationState(state: SubtitleState | undefined, tabId: number, youtubeVideoId: string, jobId: string, sessionId: string): boolean {
    const ownsState = runtime.tabSubtitleStateSessions.get(tabId) === sessionId;
    return ownsState && ((state?.type === 'loading'
        && state.youtubeVideoId === youtubeVideoId
        && state.jobId === jobId)
        || (state?.type === 'ready'
            && state.track.youtubeVideoId === youtubeVideoId
            && state.track.jobId === jobId));
}

export async function changeSavedGenerationFromPanel(message: Extract<BackgroundRequest, {
    type: 'panel.selectGeneration' | 'panel.deleteGeneration';
}>): Promise<PanelState> {
    const tab = await getActiveTab(message.windowId);
    const session = await getInstanceContext();
    if (!session || tab?.id !== message.tabId)
        throw new Error('The active tab or backend changed. Refresh the panel.');
    const tabId = message.tabId;
    if (isGenerationInFlight(tabId, message.youtubeVideoId) || runtime.tabCorrectionMutationInFlight.has(tabId)) {
        throw new Error('Wait for the current subtitle operation to finish.');
    }
    const claim = Symbol('change-saved-generation');
    const resetVersion = runtime.localStateResetVersion;
    runtime.tabCorrectionMutationInFlight.set(tabId, claim);
    const stillCurrent = async (): Promise<boolean> => {
        const activeTab = await getActiveTab(message.windowId);
        const matches = activeTab?.id === tabId
            && await activeReadyTrackMatches(tabId, message.youtubeVideoId, message.currentJobId, message.trackId, session.sessionId);
        return matches && resetVersion === runtime.localStateResetVersion && runtime.tabCorrectionMutationInFlight.get(tabId) === claim;
    };
    try {
        if (!await stillCurrent())
            throw new Error('The active transcript changed. Refresh the panel.');
        const installId = await getOrCreateInstallId();
        try {
            const correction = await runtime.subtitleApi.getLyricsCorrectionStatus(installId, message.currentJobId);
            if (correction.status === 'queued' || correction.status === 'running') {
                throw new Error('Wait for the lyrics replacement to finish.');
            }
        }
        catch (error) {
            if (!(error instanceof SubtitleApiError && error.code === 'not_found'))
                throw error;
        }
        if (message.type === 'panel.deleteGeneration') {
            if (message.jobId !== message.currentJobId || !await stillCurrent()) {
                throw new Error('The active transcript changed. Refresh the panel.');
            }
            try {
                await runtime.subtitleApi.deleteSavedGeneration(installId, message.jobId);
            }
            catch (error) {
                if (!(error instanceof SubtitleApiError && error.code === 'not_found'))
                    throw error;
            }
            await forgetRememberedTrack(message.youtubeVideoId, message.trackId);
            runtime.cachedPanelJobHistory = runtime.cachedPanelJobHistory.filter(job => job.jobId !== message.jobId);
            if (await stillCurrent()) {
                tombstoneLyricsCorrectionState(tabId);
                await publishSubtitleState(tabId, DEFAULT_SUBTITLE_STATE, session.sessionId);
                const page = parseYoutubePage(tab.url ?? '');
                if (page.supported) {
                    const recovered = await recoverSubtitleStateFromBackend(tabId, page, DEFAULT_SUBTITLE_STATE, installId, session);
                    if (await isCurrentSession(session.sessionId) && resetVersion === runtime.localStateResetVersion
                        && recovered.type === 'ready' && runtime.tabCorrectionMutationInFlight.get(tabId) === claim
                        && await activeReadyTrackMatches(tabId, page.videoId, recovered.track.jobId, recovered.track.trackId, session.sessionId)) {
                        await sendTabMessage(tabId, { type: 'background.subtitleStateChanged', subtitleState: recovered });
                    }
                }
            }
            return getPanelState({ syncBackend: false, windowId: message.windowId });
        }
        const job = await runtime.subtitleApi.getSubtitleJob(installId, message.jobId);
        if (job.jobId !== message.jobId || job.status !== 'completed' || !job.track
            || job.track.youtubeVideoId !== message.youtubeVideoId || job.track.jobId !== message.jobId
            || (job.track.expiresAt !== null && Date.parse(job.track.expiresAt) <= Date.now())) {
            throw new Error('This saved generation is no longer available.');
        }
        if (!await stillCurrent())
            throw new Error('The active transcript changed. Refresh the panel.');
        const state: SubtitleState = { type: 'ready', track: job.track };
        tombstoneLyricsCorrectionState(tabId);
        runtime.tabSubtitleStates.set(tabId, state);
        runtime.tabSubtitleStateSessions.set(tabId, session.sessionId);
        await rememberActiveTrack(job.track);
        if (await activeReadyTrackMatches(tabId, message.youtubeVideoId, job.jobId, job.track.trackId, session.sessionId)
            && resetVersion === runtime.localStateResetVersion && runtime.tabCorrectionMutationInFlight.get(tabId) === claim) {
            await sendTabMessage(tabId, { type: 'background.subtitleStateChanged', subtitleState: state });
        }
    }
    finally {
        if (runtime.tabCorrectionMutationInFlight.get(tabId) === claim)
            runtime.tabCorrectionMutationInFlight.delete(tabId);
    }
    return getPanelState({ syncBackend: false, windowId: message.windowId });
}

export async function resolveCompletedSubtitleJob(installId: string, historyJob: SubtitleJobHistoryItem, expectedSessionId?: string): Promise<JobResponse | null> {
    try {
        const job = await runtime.subtitleApi.getSubtitleJob(installId, historyJob.jobId);
        if (expectedSessionId !== undefined && !await isCurrentSession(expectedSessionId))
            return null;
        return job.status === 'completed' && job.track ? job : null;
    }
    catch (error) {
        console.warn('extension.completed_subtitle_recovery_failed', {
            youtubeVideoId: historyJob.youtubeVideoId,
            jobId: historyJob.jobId,
            trackId: historyJob.trackId,
            error: error instanceof Error ? error.message : 'Unknown completed subtitle recovery error',
        });
        return null;
    }
}

export function ensureRecoveredGenerationMonitor(tabId: number, pageStatus: SupportedYoutubePageInfo, installId: string, session: InstanceContext, initialJob?: JobResponse): void {
    if (isGenerationInFlight(tabId, pageStatus.videoId))
        return;
    const operation = Symbol('recovered-generation');
    runtime.tabOperations.set(tabId, operation);
    runtime.tabGenerationInFlight.set(tabId, pageStatus.videoId);
    let terminal = false;
    void (async () => {
        let job = initialJob;
        let failures = 0;
        while (runtime.tabOperations.get(tabId) === operation && !job) {
            const currentSession = await getInstanceContext();
            if (!currentSession || currentSession.sessionId !== session.sessionId)
                return;
            try {
                const persisted = await getTabOperation(tabId);
                if (!persisted?.jobId)
                    return;
                job = await runtime.subtitleApi.getSubtitleJob(installId, persisted.jobId);
            }
            catch (error) {
                if (error instanceof SubtitleContractError
                    || (error instanceof SubtitleApiError && error.status < 500 && error.status !== 429)
                    || ++failures >= runtime.JOB_POLL_MAX_FAILURES) {
                    terminal = true;
                    throw error;
                }
                await delay(5000);
            }
        }
        if (!job || runtime.tabOperations.get(tabId) !== operation)
            return;
        const result = await waitForCompletedSubtitleJob(tabId, pageStatus, installId, session, job, operation);
        if (!result || runtime.tabOperations.get(tabId) !== operation || !await isCurrentSession(session.sessionId))
            return;
        terminal = true;
        if (result.status === 'cancelled') {
            terminal = true;
            await clearCancelledGenerationState(tabId, result.jobId, pageStatus.videoId, session.sessionId, operation);
            return;
        }
        if (result.status === 'failed') {
            if (isCurrentLoadingState(tabId, pageStatus.videoId)) {
                await publishSubtitleState(tabId, {
                    type: 'error',
                    jobId: result.jobId,
                    youtubeVideoId: pageStatus.videoId,
                    message: publicSubtitleJobFailureMessage(result),
                }, session.sessionId);
            }
            return;
        }
        if (!result.track)
            return;
        if (isCurrentLoadingState(tabId, pageStatus.videoId)) {
            await publishSubtitleState(tabId, { type: 'ready', track: result.track }, session.sessionId);
        }
        else {
            await rememberActiveTrack(result.track);
        }
    })().catch(async (error: unknown) => {
        terminal = true;
        if (await isCurrentSession(session.sessionId)
            && runtime.tabOperations.get(tabId) === operation && isCurrentLoadingState(tabId, pageStatus.videoId)) {
            await publishSubtitleState(tabId, {
                type: 'error',
                youtubeVideoId: pageStatus.videoId,
                message: publicSubtitleErrorMessage(error),
            }, session.sessionId);
        }
    }).finally(async () => {
        if (runtime.tabOperations.get(tabId) !== operation)
            return;
        try {
            const persisted = await getTabOperation(tabId);
            const current = await getInstanceContext();
            if (terminal && runtime.tabOperations.get(tabId) === operation && current?.sessionId === session.sessionId
                && persisted?.kind === 'generation'
                && persisted.youtubeVideoId === pageStatus.videoId) {
                await clearTabOperationIfMatches(tabId, persisted);
            }
        }
        finally {
            if (runtime.tabOperations.get(tabId) === operation) {
                runtime.tabGenerationInFlight.delete(tabId);
                if (runtime.tabGenerationCancellationInFlight.get(tabId)?.operation !== operation) {
                    runtime.tabOperations.delete(tabId);
                }
            }
        }
    }).catch(() => { });
}

export async function isCurrentGenerationRecovery(tabId: number, pageStatus: SupportedYoutubePageInfo, sessionId: string, operation: symbol, expectedPersistedOperation: StoredTabOperation | null = null): Promise<boolean> {
    if (runtime.tabOperations.get(tabId) !== operation)
        return false;
    const session = await getInstanceContext();
    const tab = await browser.tabs.get(tabId).catch(() => undefined);
    const persistedOperation = await getTabOperation(tabId);
    const currentSession = await getInstanceContext();
    const currentPage = parseYoutubePage(tab?.url ?? '');
    return session?.sessionId === sessionId
        && currentSession?.sessionId === sessionId
        && currentPage.supported
        && currentPage.videoId === pageStatus.videoId
        && runtime.tabOperations.get(tabId) === operation
        && (expectedPersistedOperation === null
            ? persistedOperation === null
            : JSON.stringify(persistedOperation) === JSON.stringify(expectedPersistedOperation));
}

export function aiSelectionPayload(settings: ExtensionSettings): Pick<CreateSubtitleJobRequest, 'aiModel' | 'aiFastMode'> {
    if (settings.aiProvider === 'codex')
        return { aiModel: settings.codexModel, aiFastMode: settings.codexFastMode };
    if (settings.aiProvider === 'claude')
        return { aiModel: settings.claudeModel };
    if (settings.aiProvider === 'anthropic') {
        return { aiModel: settings.claudeApiModel, ...(CLAUDE_FAST_MODELS.includes(settings.claudeApiModel) ? { aiFastMode: settings.claudeApiFastMode } : {}) };
    }
    return {};
}

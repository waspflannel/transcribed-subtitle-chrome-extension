// Runs only in the local extension capture fixture. All text and account data are examples.
(() => {
    const state = window.reviewState;
    const lines = [
        ['Bajo la una, vuelvo a cantar.', 'Under the moon, I sing again.', ['Bajo', 'la', 'una,', 'vuelvo', 'a', 'cantar.']],
        ['La música me lleva a otro lugar.', 'The music takes me somewhere else.', ['La', 'música', 'me', 'lleva', 'a', 'otro', 'lugar.']],
        ['Cada palabra me acerca un poco más.', 'Every word brings me a little closer.', ['Cada', 'palabra', 'me', 'acerca', 'un', 'poco', 'más.']],
    ];
    const track = {
        trackId: 'track-original', jobId: 'job-1', youtubeVideoId: 'aBcDeFgHiJk',
        sourceLanguage: 'spa', targetLanguage: 'eng', generatedAt: '2026-09-16T00:00:00Z',
        expiresAt: '2099-01-01T00:00:00Z', webVtt: 'WEBVTT\n',
        cues: lines.map(([sourceText, translatedText, words], index) => ({
            cueId: 'cue-' + (index + 1), index, startMs: 12000 + index * 5000, endMs: 16000 + index * 5000,
            sourceText, translatedText,
            tokens: words.map((text, tokenIndex) => ({index: tokenIndex, text, normalizedText: text.toLowerCase()})),
        })),
    };
    Object.assign(state.settings, {sourceLanguage: 'spa', targetLanguage: 'eng', showRomanization: false, overlayAttachedToVideo: true});
    state.pageTitle = 'Evening song · Example track';
    state.pageVideoDurationSeconds = 181;
    Object.assign(state.accountState, {name: 'Example learner', email: 'learner@example.test', planName: 'Plus', monthlyMinuteLimit: 240, monthlyMinutesRemaining: 180, monthlyMinutesUsed: 60});
    if (state.subtitleState.type === 'ready') state.subtitleState.track = track;
    const sendMessage = window.reviewBrowser.runtime.sendMessage;
    window.reviewBrowser.runtime.sendMessage = async (message) => {
        if (message.type === 'panel.listGenerations') return {jobs: [{
            jobId: track.jobId, trackId: track.trackId, status: 'completed',
            sourceLanguage: 'spa', targetLanguage: 'eng', youtubeVideoId: track.youtubeVideoId, aiProvider: 'openai',
        }]};
        return sendMessage(message);
    };
    window.reviewRefresh();
})();

const language = new URLSearchParams(location.search).get('lang') === 'heb' ? 'heb' : 'ara';
const words = language === 'heb'
  ? [['אני', 'ani'], ['אוהב', 'ohev'], ['מוזיקה', 'muzika']]
  : [['أنا', 'ana'], ['أحب', 'uhibbu'], ['الموسيقى', 'al musiqa']];
const cue = (id, items) => ({ cueId: 'cue-' + id, index: id - 1, startMs: (id - 1) * 3000, endMs: id * 3000,
  sourceText: items.map(item => item[0]).join(' '), translatedText: 'I love music',
  tokens: items.map(([text, romanization], index) => ({ index, text, normalizedText: text, romanization })) });
const track = { trackId: 'track-original', jobId: 'job-1', youtubeVideoId: 'aBcDeFgHiJk', sourceLanguage: language,
  targetLanguage: 'eng', generatedAt: '2026-09-15T00:00:00Z', expiresAt: '2099-01-01T00:00:00Z', webVtt: 'WEBVTT\n',
  cues: [cue(1, words), cue(2, [['2026', 'twenty twenty-six'], ...words, ['YouTube!', 'YouTube!']]),
    ...Array.from({ length: 24 }, (_, index) => cue(index + 3, words))] };
const settings = { sourceLanguage: language, targetLanguage: 'eng', aiProvider: 'openai', overlayVisible: true,
  overlayPosition: 'bottom', captionFontSize: 'medium', captionDensity: 'comfortable', captionContrastTheme: 'default',
  keyboardShortcutsEnabled: true, showRomanization: true, showTranslation: true, showGloss: true,
  blurSourceWords: false, blurRomanization: false, blurTranslation: false, pauseOnWordHover: true, subtitleTimingOffsetSeconds: 0 };
const state = { installId: 'install_fixture', settings, activeTabId: 1,
  pageStatus: { supported: true, videoId: track.youtubeVideoId, url: 'https://www.youtube.com/watch?v=' + track.youtubeVideoId, mediaKind: 'video' },
  pageTitle: 'Isolated ' + language + ' remediation fixture', pageVideoDurationSeconds: 120,
  accountState: { status: 'authenticated', id: 'fixture-user', email: 'fixture@example.test', name: 'Fixture', emailVerified: false,
    planName: 'Pro', tierName: 'pro', tierSpeedLabel: 'Fast', monthlyMinuteLimit: 100, monthlyMinutesUsed: 0,
    monthlyMinutesPending: 0, monthlyMinutesRemaining: 100, resetAt: '2026-10-01T00:00:00Z', upgradeAvailable: false },
  subtitleState: { type: 'ready', track }, jobHistory: [], lyricsCorrection: null };
window.reviewState = state;
window.reviewCalls = [];
window.reviewRefresh = () => document.dispatchEvent(new Event('visibilitychange'));
if (new URLSearchParams(location.search).has('generation')) {
  state.subtitleState = { type: 'no-track' };
  state.pageTitle = 'Generation confirmation fixture';
  state.pageVideoDurationSeconds = 181;
}
window.reviewPatchMetadata = () => {
  track.cues.at(-1).tokens[0].gloss = 'Arrived asynchronously ' + window.reviewCalls.length;
  document.dispatchEvent(new Event('visibilitychange'));
};
const nop = { addListener() {}, removeListener() {} };
window.reviewBrowser = {
  runtime: { connect: () => ({ onDisconnect: nop }), onMessage: nop, sendMessage: async message => {
    window.reviewCalls.push(message);
    if (message.type === 'panel.listGenerations') return { jobs: [{ jobId: track.jobId, trackId: track.trackId, status: 'completed',
      sourceLanguage: language, targetLanguage: 'eng', youtubeVideoId: track.youtubeVideoId, aiProvider: 'openai' }] };
    if (message.type === 'panel.getActiveCue') return { ok: true, cueId: null, youtubeVideoId: track.youtubeVideoId, trackId: track.trackId, tabId: 1, windowId: 1 };
    if (message.type === 'panel.updateSettings') Object.assign(settings, message.patch);
    if (message.type === 'panel.seekToCue') return { ok: true };
    if (message.type === 'panel.quickFixToken' || message.type === 'panel.submitLyricsCorrection') return { ok: false, error: 'Fixture: no provider calls.' };
    if (message.type === 'panel.generateSubtitles') {
      if (window.reviewHoldGeneration) return new Promise(resolve => { window.reviewResolveGeneration = resolve; });
      return { ok: false, error: 'Fixture generation failed. Try again.' };
    }
    return structuredClone(state);
  } }, windows: { getCurrent: async () => ({ id: 1 }) }, tabs: { onActivated: nop, onUpdated: nop },
};

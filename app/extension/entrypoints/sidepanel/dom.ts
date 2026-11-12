export interface PanelDom {
  readyToolbar: HTMLElement;
  viewProgressButton: HTMLButtonElement;
  progressSummaryLabel: HTMLElement;
  progressSummary: HTMLElement;
  backTranscriptButton: HTMLButtonElement;
  tabButtons: HTMLButtonElement[];
  panels: HTMLElement[];
  transcriptSearch: HTMLInputElement;
  transcriptList: HTMLElement;
  transcriptStatus: HTMLElement;
  collapseButton: HTMLButtonElement;
  nowPlayingTitle: HTMLElement;
  nowPlayingMeta: HTMLElement;
  watchUnsupported: HTMLElement;
  watchSetup: HTMLElement;
  watchReady: HTMLElement;
  correctionTerminalStatus: HTMLElement;
  correctionTerminalMessage: HTMLElement;
  dismissCorrectionStatusButton: HTMLButtonElement;
  correctionCancelError: HTMLElement;
  correctionSyncError: HTMLElement;
  lyricsEditPanel: HTMLElement;
  toggleLyricsEditButton: HTMLButtonElement;
  lyricsCorrectionForm: HTMLFormElement;
  lyricsCorrectionTextarea: HTMLTextAreaElement;
  lyricsCorrectionCount: HTMLElement;
  lyricsCorrectionError: HTMLElement;
  lyricsCorrectionStatus: HTMLElement;
  lyricsCorrectionButton: HTMLButtonElement;
  lyricsConfirmation: HTMLElement;
  confirmLyricsCorrectionButton: HTMLButtonElement;
  cancelLyricsConfirmationButton: HTMLButtonElement;
  cancelLyricsCorrectionButton: HTMLButtonElement;
  cancelGenerationButton: HTMLButtonElement;
  progressCopy: HTMLElement;
  toggleSetupButton: HTMLButtonElement;
  generateButton: HTMLButtonElement;
  generateNote: HTMLElement;
  clearStateButton: HTMLButtonElement;
  resetTimingButton: HTMLButtonElement;
  sourceLanguageSearchInput: HTMLInputElement;
  targetLanguageSearchInput: HTMLInputElement;
  sourceLanguageSelected: HTMLElement;
  targetLanguageSelected: HTMLElement;
  sourceLanguageList: HTMLElement;
  targetLanguageList: HTMLElement;
  aiProviderSelect: HTMLSelectElement;
  overlayVisibleInput: HTMLInputElement;
  overlayAttachedToVideoInput: HTMLInputElement;
  showRomanizationInput: HTMLInputElement;
  showTranslationInput: HTMLInputElement;
  showGlossInput: HTMLInputElement;
  blurSourceWordsInput: HTMLInputElement;
  blurRomanizationInput: HTMLInputElement;
  blurTranslationInput: HTMLInputElement;
  pauseOnWordHoverInput: HTMLInputElement;
  keyboardShortcutsEnabledInput: HTMLInputElement;
  timingOffsetRangeInput: HTMLInputElement;
  timingOffsetNumberInput: HTMLInputElement;
  timingOffsetOutput: HTMLOutputElement;
  progressContainer: HTMLElement;
  progressPercent: HTMLElement;
  progressActivity: HTMLElement;
  progressBar: HTMLElement;
  progressStages: HTMLElement;
  progressLabel: HTMLElement;
  jobsList: HTMLElement;
  jobsError: HTMLElement;
  settingsLanguageSummary: HTMLElement;
  shortcutHelpList: HTMLElement;
  statusBanner: HTMLElement;
  screenHead: HTMLElement;
  screenTitle: HTMLElement;
  languageSummary: HTMLElement;
  aiSummary: HTMLElement;
  aiSummarySettings: HTMLElement;
  trackButton: HTMLButtonElement;
  trackPair: HTMLElement;
  trackModel: HTMLElement;
  trackMenu: HTMLElement;
  toolsButton: HTMLButtonElement;
  toolsMenu: HTMLElement;
  deleteCurrentButton: HTMLButtonElement;
  deleteCurrentLabel: HTMLElement;
  searchBox: HTMLElement;
  searchToggle: HTMLButtonElement;
  timingButton: HTMLButtonElement;
  timingMenu: HTMLElement;
  timingOffsetLarge: HTMLOutputElement;
  captionContrastInput: HTMLInputElement;
  settingSegments: HTMLElement[];
  blurRomanizationChip: HTMLElement;
  lyricsConfirmationBox: HTMLElement;
  lyricsAiSummary: HTMLElement;
  lyricsAiReadinessInline: HTMLElement;
  toast: HTMLElement;
  confirmDialog: HTMLDialogElement;
  tabInk: HTMLElement;
  scroll: HTMLElement;
  historySelectButton: HTMLButtonElement;
  historySelectAllButton: HTMLButtonElement;
  historyDeleteButton: HTMLButtonElement;
  historyDeleteLabel: HTMLElement;
  historyBulk: HTMLElement;
  historyCount: HTMLElement;
  history: HTMLElement;
  sheetLanguages: HTMLElement;
  sheetAi: HTMLElement;
  sheetLyricsAi: HTMLElement;
}

export function getPanelDom(root: ParentNode = document): PanelDom {
  return {
    readyToolbar: query(root, '.ready-toolbar', HTMLElement),
    viewProgressButton: query(root, '[data-action="view-progress"]', HTMLButtonElement),
    progressSummaryLabel: query(root, '[data-progress-summary-label]', HTMLElement),
    progressSummary: query(root, '[data-progress-summary]', HTMLElement),
    backTranscriptButton: query(root, '[data-action="back-transcript"]', HTMLButtonElement),
    tabButtons: queryAll<HTMLButtonElement>(root, '[data-tab]'),
    panels: queryAll<HTMLElement>(root, '[data-panel]'),
    transcriptSearch: query<HTMLInputElement>(root, '[data-transcript-search]', HTMLInputElement),
    transcriptList: query<HTMLElement>(root, '[data-transcript-list]', HTMLElement),
    transcriptStatus: query<HTMLElement>(root, '[data-transcript-status]', HTMLElement),
    collapseButton: query(root, '[data-action="collapse-panel"]', HTMLButtonElement),
    nowPlayingTitle: query(root, '[data-now-playing-title]', HTMLElement),
    nowPlayingMeta: query(root, '[data-now-playing-meta]', HTMLElement),
    watchUnsupported: query(root, '[data-watch-unsupported]', HTMLElement),
    watchSetup: query(root, '[data-watch-setup]', HTMLElement),
    watchReady: query(root, '[data-watch-ready]', HTMLElement),
    correctionTerminalStatus: query(root, '[data-correction-terminal-status]', HTMLElement),
    correctionTerminalMessage: query(root, '[data-correction-terminal-message]', HTMLElement),
    dismissCorrectionStatusButton: query(root, '[data-action="dismiss-correction-status"]', HTMLButtonElement),
    correctionCancelError: query(root, '[data-correction-cancel-error]', HTMLElement),
    correctionSyncError: query(root, '[data-correction-sync-error]', HTMLElement),
    lyricsEditPanel: query(root, '[data-lyrics-edit-panel]', HTMLElement),
    toggleLyricsEditButton: query(root, '[data-action="toggle-lyrics-edit"]', HTMLButtonElement),
    lyricsCorrectionForm: query(root, '[data-lyrics-correction-form]', HTMLFormElement),
    lyricsCorrectionTextarea: query(root, '[data-lyrics-correction-textarea]', HTMLTextAreaElement),
    lyricsCorrectionCount: query(root, '[data-lyrics-correction-count]', HTMLElement),
    lyricsCorrectionError: query(root, '[data-lyrics-correction-error]', HTMLElement),
    lyricsCorrectionStatus: query(root, '[data-lyrics-correction-status]', HTMLElement),
    lyricsCorrectionButton: query(root, '[data-action="apply-lyrics-correction"]', HTMLButtonElement),
    lyricsConfirmation: query(root, '[data-lyrics-confirmation]', HTMLElement),
    confirmLyricsCorrectionButton: query(root, '[data-action="confirm-lyrics-correction"]', HTMLButtonElement),
    cancelLyricsConfirmationButton: query(root, '[data-action="cancel-lyrics-confirmation"]', HTMLButtonElement),
    cancelLyricsCorrectionButton: query(root, '[data-action="cancel-lyrics-correction"]', HTMLButtonElement),
    cancelGenerationButton: query(root, '[data-action="cancel-generation"]', HTMLButtonElement),
    toggleSetupButton: query(root, '[data-action="toggle-setup"]', HTMLButtonElement),
    generateButton: query(root, '[data-action="generate"]', HTMLButtonElement),
    generateNote: query(root, '[data-generate-note]', HTMLElement),
    clearStateButton: query(root, '[data-action="clear-state"]', HTMLButtonElement),
    resetTimingButton: query(root, '[data-action="reset-timing"]', HTMLButtonElement),
    sourceLanguageSearchInput: query(root, 'input[name="sourceLanguageSearch"]', HTMLInputElement),
    targetLanguageSearchInput: query(root, 'input[name="targetLanguageSearch"]', HTMLInputElement),
    sourceLanguageSelected: query(root, '[data-source-language-selected]', HTMLElement),
    targetLanguageSelected: query(root, '[data-target-language-selected]', HTMLElement),
    sourceLanguageList: query(root, '[data-source-language-list]', HTMLElement),
    targetLanguageList: query(root, '[data-target-language-list]', HTMLElement),
    aiProviderSelect: query(root, 'select[name="aiProvider"]', HTMLSelectElement),
    overlayVisibleInput: query(root, 'input[name="overlayVisible"]', HTMLInputElement),
    overlayAttachedToVideoInput: query(root, 'input[name="overlayAttachedToVideo"]', HTMLInputElement),
    showRomanizationInput: query(root, 'input[name="showRomanization"]', HTMLInputElement),
    showTranslationInput: query(root, 'input[name="showTranslation"]', HTMLInputElement),
    showGlossInput: query(root, 'input[name="showGloss"]', HTMLInputElement),
    blurSourceWordsInput: query(root, 'input[name="blurSourceWords"]', HTMLInputElement),
    blurRomanizationInput: query(root, 'input[name="blurRomanization"]', HTMLInputElement),
    blurTranslationInput: query(root, 'input[name="blurTranslation"]', HTMLInputElement),
    pauseOnWordHoverInput: query(root, 'input[name="pauseOnWordHover"]', HTMLInputElement),
    keyboardShortcutsEnabledInput: query(root, 'input[name="keyboardShortcutsEnabled"]', HTMLInputElement),
    timingOffsetRangeInput: query(root, 'input[name="subtitleTimingOffsetSeconds"]', HTMLInputElement),
    timingOffsetNumberInput: query(root, 'input[name="subtitleTimingOffsetNumber"]', HTMLInputElement),
    timingOffsetOutput: query(root, '[data-timing-offset]', HTMLOutputElement),
    progressContainer: query(root, '[data-progress]', HTMLElement),
    progressPercent: query(root, '[data-progress-percent]', HTMLElement),
    progressActivity: query(root, '[data-progress-activity]', HTMLElement),
    progressBar: query(root, '[data-progress-bar]', HTMLElement),
    progressStages: query(root, '[data-progress-stages]', HTMLElement),
    progressLabel: query(root, '[data-progress-label]', HTMLElement),
    progressCopy: query(root, '[data-progress-copy]', HTMLElement),
    jobsList: query(root, '[data-jobs-list]', HTMLElement),
    jobsError: query(root, '[data-jobs-error]', HTMLElement),
    settingsLanguageSummary: query(root, '[data-settings-language-summary]', HTMLElement),
    shortcutHelpList: query(root, '[data-shortcut-help]', HTMLElement),
    statusBanner: query(root, '[data-status]', HTMLElement),
    screenHead: query(root, '[data-screen-head]', HTMLElement),
    screenTitle: query(root, '[data-screen-title]', HTMLElement),
    languageSummary: query(root, '[data-language-summary]', HTMLElement),
    aiSummary: query(root, '[data-ai-summary]', HTMLElement),
    aiSummarySettings: query(root, '[data-ai-summary-settings]', HTMLElement),
    trackButton: query(root, '[data-action="track-menu"]', HTMLButtonElement),
    trackPair: query(root, '[data-track-pair]', HTMLElement),
    trackModel: query(root, '[data-track-model]', HTMLElement),
    trackMenu: query(root, '[data-track-menu]', HTMLElement),
    toolsButton: query(root, '[data-action="tools-menu"]', HTMLButtonElement),
    toolsMenu: query(root, '[data-tools-menu]', HTMLElement),
    deleteCurrentButton: query(root, '[data-action="delete-current"]', HTMLButtonElement),
    deleteCurrentLabel: query(root, '[data-delete-current-label]', HTMLElement),
    searchBox: query(root, '[data-search-box]', HTMLElement),
    searchToggle: query(root, '[data-action="toggle-search"]', HTMLButtonElement),
    timingButton: query(root, '[data-action="timing-menu"]', HTMLButtonElement),
    timingMenu: query(root, '[data-timing-menu]', HTMLElement),
    timingOffsetLarge: query(root, '[data-timing-offset-large]', HTMLOutputElement),
    captionContrastInput: query(root, 'input[name="captionContrastHigh"]', HTMLInputElement),
    settingSegments: queryAll<HTMLElement>(root, '.seg[data-setting]'),
    blurRomanizationChip: query(root, '[data-blur-romanization-chip]', HTMLElement),
    lyricsConfirmationBox: query(root, '[data-lyrics-confirmation-box]', HTMLElement),
    lyricsAiSummary: query(root, '[data-lyrics-ai-summary]', HTMLElement),
    lyricsAiReadinessInline: query(root, '[data-lyrics-ai-readiness-inline]', HTMLElement),
    toast: query(root, '[data-toast]', HTMLElement),
    confirmDialog: query(root, '[data-confirm-dialog]', HTMLDialogElement),
    tabInk: query(root, '[data-tab-ink]', HTMLElement),
    scroll: query(root, '[data-scroll]', HTMLElement),
    historySelectButton: query(root, '[data-action="history-select"]', HTMLButtonElement),
    historySelectAllButton: query(root, '[data-action="history-select-all"]', HTMLButtonElement),
    historyDeleteButton: query(root, '[data-action="history-delete"]', HTMLButtonElement),
    historyDeleteLabel: query(root, '[data-history-delete-label]', HTMLElement),
    historyBulk: query(root, '[data-history-bulk]', HTMLElement),
    historyCount: query(root, '[data-history-count]', HTMLElement),
    history: query(root, '[data-history]', HTMLElement),
    sheetLanguages: query(root, '[data-sheet-panel="languages"]', HTMLElement),
    sheetAi: query(root, '[data-sheet-panel="ai"]', HTMLElement),
    sheetLyricsAi: query(root, '[data-sheet-panel="lyrics-ai"]', HTMLElement),
  };
}

function query<TElement extends Element>(
  root: ParentNode,
  selector: string,
  expectedType: { new (...args: never[]): TElement },
): TElement {
  const element = root.querySelector(selector);

  if (!(element instanceof expectedType)) {
    throw new Error(`Panel markup is missing ${selector}.`);
  }

  return element;
}

function queryAll<TElement extends Element>(root: ParentNode, selector: string): TElement[] {
  return Array.from(root.querySelectorAll<TElement>(selector));
}

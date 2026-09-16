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
  nowPlayingEyebrow: HTMLElement;
  nowPlayingTitle: HTMLElement;
  nowPlayingMeta: HTMLElement;
  statusBanner: HTMLElement;
  watchUnsupported: HTMLElement;
  watchSignin: HTMLElement;
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
  quickFixStatus: HTMLElement;
  progressCopy: HTMLElement;
  openAccountButton: HTMLButtonElement;
  toggleLanguagesButton: HTMLButtonElement;
  languageExpand: HTMLElement;
  toggleSetupButton: HTMLButtonElement;
  pairSourceCode: HTMLElement;
  pairSourceName: HTMLElement;
  pairTargetCode: HTMLElement;
  pairTargetName: HTMLElement;
  generateButton: HTMLButtonElement;
  generationConfirmation: HTMLDialogElement;
  generationConfirmationSummary: HTMLElement;
  confirmGenerationButton: HTMLButtonElement;
  cancelGenerationConfirmationButton: HTMLButtonElement;
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
  overlayPositionSelect: HTMLSelectElement;
  captionFontSizeSelect: HTMLSelectElement;
  captionDensitySelect: HTMLSelectElement;
  captionContrastThemeSelect: HTMLSelectElement;
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
  usageSummary: HTMLElement;
  usageRemaining: HTMLElement;
  usageBar: HTMLElement;
  usagePlan: HTMLElement;
  usagePending: HTMLElement;
  usageReset: HTMLElement;
  accountStatus: HTMLElement;
  accountPlan: HTMLElement;
  accountSpeed: HTMLElement;
  accountModel: HTMLElement;
  accountLoginForm: HTMLFormElement;
  accountEmailInput: HTMLInputElement;
  accountPasswordInput: HTMLInputElement;
  accountLoginButton: HTMLButtonElement;
  logoutButton: HTMLButtonElement;
  accountFeedback: HTMLElement;
  featureList: HTMLElement;
  accountBillingLink: HTMLElement;
  settingsLanguageSummary: HTMLElement;
  shortcutHelpList: HTMLElement;
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
    nowPlayingEyebrow: query(root, '[data-now-playing-eyebrow]', HTMLElement),
    nowPlayingTitle: query(root, '[data-now-playing-title]', HTMLElement),
    nowPlayingMeta: query(root, '[data-now-playing-meta]', HTMLElement),
    statusBanner: query(root, '[data-status]', HTMLElement),
    watchUnsupported: query(root, '[data-watch-unsupported]', HTMLElement),
    watchSignin: query(root, '[data-watch-signin]', HTMLElement),
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
    quickFixStatus: query(root, '[data-quick-fix-status]', HTMLElement),
    openAccountButton: query(root, '[data-action="open-account"]', HTMLButtonElement),
    toggleLanguagesButton: query(root, '[data-action="toggle-languages"]', HTMLButtonElement),
    languageExpand: query(root, '[data-language-expand]', HTMLElement),
    toggleSetupButton: query(root, '[data-action="toggle-setup"]', HTMLButtonElement),
    pairSourceCode: query(root, '[data-pair-source-code]', HTMLElement),
    pairSourceName: query(root, '[data-pair-source-name]', HTMLElement),
    pairTargetCode: query(root, '[data-pair-target-code]', HTMLElement),
    pairTargetName: query(root, '[data-pair-target-name]', HTMLElement),
    generateButton: query(root, '[data-action="generate"]', HTMLButtonElement),
    generationConfirmation: query(root, '[data-generation-confirmation]', HTMLDialogElement),
    generationConfirmationSummary: query(root, '[data-generation-confirmation-summary]', HTMLElement),
    confirmGenerationButton: query(root, '[data-action="confirm-generation"]', HTMLButtonElement),
    cancelGenerationConfirmationButton: query(root, '[data-action="cancel-generation-confirmation"]', HTMLButtonElement),
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
    overlayPositionSelect: query(root, 'select[name="overlayPosition"]', HTMLSelectElement),
    captionFontSizeSelect: query(root, 'select[name="captionFontSize"]', HTMLSelectElement),
    captionDensitySelect: query(root, 'select[name="captionDensity"]', HTMLSelectElement),
    captionContrastThemeSelect: query(root, 'select[name="captionContrastTheme"]', HTMLSelectElement),
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
    usageSummary: query(root, '[data-usage-summary]', HTMLElement),
    usageRemaining: query(root, '[data-usage-remaining]', HTMLElement),
    usageBar: query(root, '[data-usage-bar]', HTMLElement),
    usagePlan: query(root, '[data-usage-plan]', HTMLElement),
    usagePending: query(root, '[data-usage-pending]', HTMLElement),
    usageReset: query(root, '[data-usage-reset]', HTMLElement),
    accountStatus: query(root, '[data-account-status]', HTMLElement),
    accountPlan: query(root, '[data-account-plan]', HTMLElement),
    accountSpeed: query(root, '[data-account-speed]', HTMLElement),
    accountModel: query(root, '[data-account-model]', HTMLElement),
    accountLoginForm: query(root, '[data-account-login-form]', HTMLFormElement),
    accountEmailInput: query(root, 'input[name="accountEmail"]', HTMLInputElement),
    accountPasswordInput: query(root, 'input[name="accountPassword"]', HTMLInputElement),
    accountLoginButton: query(root, '[data-action="login"]', HTMLButtonElement),
    logoutButton: query(root, '[data-action="logout"]', HTMLButtonElement),
    accountFeedback: query(root, '[data-account-feedback]', HTMLElement),
    featureList: query(root, '[data-feature-list]', HTMLElement),
    accountBillingLink: query(root, '[data-account-billing]', HTMLElement),
    settingsLanguageSummary: query(root, '[data-settings-language-summary]', HTMLElement),
    shortcutHelpList: query(root, '[data-shortcut-help]', HTMLElement),
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

export interface PopupDom {
  statusText: HTMLParagraphElement;
  planPill: HTMLElement;
  videoText: HTMLElement;
  videoDurationText: HTMLElement;
  trackText: HTMLElement;
  jobText: HTMLElement;
  refreshButton: HTMLButtonElement;
  generateButton: HTMLButtonElement;
  clearStateButton: HTMLButtonElement;
  resetTimingButton: HTMLButtonElement;
  sourceLanguageSearchInput: HTMLInputElement;
  targetLanguageSearchInput: HTMLInputElement;
  sourceLanguageSelected: HTMLElement;
  targetLanguageSelected: HTMLElement;
  sourceLanguageList: HTMLElement;
  targetLanguageList: HTMLElement;
  overlayPositionSelect: HTMLSelectElement;
  captionFontSizeSelect: HTMLSelectElement;
  captionDensitySelect: HTMLSelectElement;
  captionContrastThemeSelect: HTMLSelectElement;
  overlayVisibleInput: HTMLInputElement;
  showRomanizationInputs: HTMLInputElement[];
  showTranslationInput: HTMLInputElement;
  showGlossInput: HTMLInputElement;
  blurSourceWordsInput: HTMLInputElement;
  blurRomanizationInput: HTMLInputElement;
  blurTranslationInput: HTMLInputElement;
  pauseOnWordHoverInput: HTMLInputElement;
  keyboardShortcutsEnabledInput: HTMLInputElement;
  fullTrackEnrichmentInput: HTMLInputElement;
  timingOffsetRangeInput: HTMLInputElement;
  timingOffsetNumberInput: HTMLInputElement;
  timingOffsetOutput: HTMLOutputElement;
  progressContainer: HTMLElement;
  progressLabel: HTMLElement;
  progressPercent: HTMLElement;
  progressBar: HTMLElement;
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
  accountLoginForm: HTMLFormElement;
  accountEmailInput: HTMLInputElement;
  accountPasswordInput: HTMLInputElement;
  accountLoginButton: HTMLButtonElement;
  logoutButton: HTMLButtonElement;
  accountFeedback: HTMLElement;
  featureList: HTMLElement;
  settingsLanguageSummary: HTMLElement;
  shortcutHelpList: HTMLElement;
  tabButtons: HTMLButtonElement[];
  panels: HTMLElement[];
}

export function getPopupDom(root: ParentNode = document): PopupDom {
  return {
    statusText: requiredElement(root, '[data-status]', HTMLParagraphElement),
    planPill: requiredElement(root, '[data-plan-pill]', HTMLElement),
    videoText: requiredElement(root, '[data-video-label]', HTMLElement),
    videoDurationText: requiredElement(root, '[data-video-duration]', HTMLElement),
    trackText: requiredElement(root, '[data-track-label]', HTMLElement),
    jobText: requiredElement(root, '[data-job-label]', HTMLElement),
    refreshButton: requiredElement(root, '[data-action="refresh"]', HTMLButtonElement),
    generateButton: requiredElement(root, '[data-action="generate"]', HTMLButtonElement),
    clearStateButton: requiredElement(root, '[data-action="clear-state"]', HTMLButtonElement),
    resetTimingButton: requiredElement(root, '[data-action="reset-timing"]', HTMLButtonElement),
    sourceLanguageSearchInput: requiredElement(root, 'input[name="sourceLanguageSearch"]', HTMLInputElement),
    targetLanguageSearchInput: requiredElement(root, 'input[name="targetLanguageSearch"]', HTMLInputElement),
    sourceLanguageSelected: requiredElement(root, '[data-source-language-selected]', HTMLElement),
    targetLanguageSelected: requiredElement(root, '[data-target-language-selected]', HTMLElement),
    sourceLanguageList: requiredElement(root, '[data-source-language-list]', HTMLElement),
    targetLanguageList: requiredElement(root, '[data-target-language-list]', HTMLElement),
    overlayPositionSelect: requiredElement(root, 'select[name="overlayPosition"]', HTMLSelectElement),
    captionFontSizeSelect: requiredElement(root, 'select[name="captionFontSize"]', HTMLSelectElement),
    captionDensitySelect: requiredElement(root, 'select[name="captionDensity"]', HTMLSelectElement),
    captionContrastThemeSelect: requiredElement(root, 'select[name="captionContrastTheme"]', HTMLSelectElement),
    overlayVisibleInput: requiredElement(root, 'input[name="overlayVisible"]', HTMLInputElement),
    showRomanizationInputs: Array.from(root.querySelectorAll<HTMLInputElement>('input[name="showRomanization"]')),
    showTranslationInput: requiredElement(root, 'input[name="showTranslation"]', HTMLInputElement),
    showGlossInput: requiredElement(root, 'input[name="showGloss"]', HTMLInputElement),
    blurSourceWordsInput: requiredElement(root, 'input[name="blurSourceWords"]', HTMLInputElement),
    blurRomanizationInput: requiredElement(root, 'input[name="blurRomanization"]', HTMLInputElement),
    blurTranslationInput: requiredElement(root, 'input[name="blurTranslation"]', HTMLInputElement),
    pauseOnWordHoverInput: requiredElement(root, 'input[name="pauseOnWordHover"]', HTMLInputElement),
    keyboardShortcutsEnabledInput: requiredElement(root, 'input[name="keyboardShortcutsEnabled"]', HTMLInputElement),
    fullTrackEnrichmentInput: requiredElement(root, 'input[name="fullTrackEnrichment"]', HTMLInputElement),
    timingOffsetRangeInput: requiredElement(root, 'input[name="subtitleTimingOffsetSeconds"]', HTMLInputElement),
    timingOffsetNumberInput: requiredElement(root, 'input[name="subtitleTimingOffsetNumber"]', HTMLInputElement),
    timingOffsetOutput: requiredElement(root, '[data-timing-offset]', HTMLOutputElement),
    progressContainer: requiredElement(root, '[data-progress]', HTMLElement),
    progressLabel: requiredElement(root, '[data-progress-label]', HTMLElement),
    progressPercent: requiredElement(root, '[data-progress-percent]', HTMLElement),
    progressBar: requiredElement(root, '[data-progress-bar]', HTMLElement),
    jobsList: requiredElement(root, '[data-jobs-list]', HTMLElement),
    jobsError: requiredElement(root, '[data-jobs-error]', HTMLElement),
    usageSummary: requiredElement(root, '[data-usage-summary]', HTMLElement),
    usageRemaining: requiredElement(root, '[data-usage-remaining]', HTMLElement),
    usageBar: requiredElement(root, '[data-usage-bar]', HTMLElement),
    usagePlan: requiredElement(root, '[data-usage-plan]', HTMLElement),
    usagePending: requiredElement(root, '[data-usage-pending]', HTMLElement),
    usageReset: requiredElement(root, '[data-usage-reset]', HTMLElement),
    accountStatus: requiredElement(root, '[data-account-status]', HTMLElement),
    accountPlan: requiredElement(root, '[data-account-plan]', HTMLElement),
    accountSpeed: requiredElement(root, '[data-account-speed]', HTMLElement),
    accountLoginForm: requiredElement(root, '[data-account-login-form]', HTMLFormElement),
    accountEmailInput: requiredElement(root, 'input[name="accountEmail"]', HTMLInputElement),
    accountPasswordInput: requiredElement(root, 'input[name="accountPassword"]', HTMLInputElement),
    accountLoginButton: requiredElement(root, '[data-action="login"]', HTMLButtonElement),
    logoutButton: requiredElement(root, '[data-action="logout"]', HTMLButtonElement),
    accountFeedback: requiredElement(root, '[data-account-feedback]', HTMLElement),
    featureList: requiredElement(root, '[data-feature-list]', HTMLElement),
    settingsLanguageSummary: requiredElement(root, '[data-settings-language-summary]', HTMLElement),
    shortcutHelpList: requiredElement(root, '[data-shortcut-help]', HTMLElement),
    tabButtons: Array.from(root.querySelectorAll<HTMLButtonElement>('[data-tab]')),
    panels: Array.from(root.querySelectorAll<HTMLElement>('[data-panel]')),
  };
}

function requiredElement<TElement extends Element>(
  root: ParentNode,
  selector: string,
  expectedType: { new (...args: never[]): TElement },
): TElement {
  const element = root.querySelector(selector);

  if (!(element instanceof expectedType)) {
    throw new Error(`Popup markup is missing ${selector}.`);
  }

  return element;
}

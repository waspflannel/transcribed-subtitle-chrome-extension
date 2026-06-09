export interface PanelDom {
  railButtons: HTMLButtonElement[];
  panels: HTMLElement[];
  transcriptSearch: HTMLInputElement;
  transcriptList: HTMLElement;
  transcriptStatus: HTMLElement;
  collapseButton: HTMLButtonElement;
  nowPlayingEyebrow: HTMLElement;
  nowPlayingTitle: HTMLElement;
  nowPlayingMeta: HTMLElement;
  statusText: HTMLElement;
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
}

export function getPanelDom(root: ParentNode = document): PanelDom {
  return {
    railButtons: queryAll<HTMLButtonElement>(root, '[data-tab]'),
    panels: queryAll<HTMLElement>(root, '[data-panel]'),
    transcriptSearch: query<HTMLInputElement>(root, '[data-transcript-search]', HTMLInputElement),
    transcriptList: query<HTMLElement>(root, '[data-transcript-list]', HTMLElement),
    transcriptStatus: query<HTMLElement>(root, '[data-transcript-status]', HTMLElement),
    collapseButton: query(root, '[data-action="collapse-panel"]', HTMLButtonElement),
    nowPlayingEyebrow: query(root, '[data-now-playing-eyebrow]', HTMLElement),
    nowPlayingTitle: query(root, '[data-now-playing-title]', HTMLElement),
    nowPlayingMeta: query(root, '[data-now-playing-meta]', HTMLElement),
    statusText: query(root, '[data-status]', HTMLElement),
    generateButton: query(root, '[data-action="generate"]', HTMLButtonElement),
    clearStateButton: query(root, '[data-action="clear-state"]', HTMLButtonElement),
    resetTimingButton: query(root, '[data-action="reset-timing"]', HTMLButtonElement),
    sourceLanguageSearchInput: query(root, 'input[name="sourceLanguageSearch"]', HTMLInputElement),
    targetLanguageSearchInput: query(root, 'input[name="targetLanguageSearch"]', HTMLInputElement),
    sourceLanguageSelected: query(root, '[data-source-language-selected]', HTMLElement),
    targetLanguageSelected: query(root, '[data-target-language-selected]', HTMLElement),
    sourceLanguageList: query(root, '[data-source-language-list]', HTMLElement),
    targetLanguageList: query(root, '[data-target-language-list]', HTMLElement),
    overlayPositionSelect: query(root, 'select[name="overlayPosition"]', HTMLSelectElement),
    captionFontSizeSelect: query(root, 'select[name="captionFontSize"]', HTMLSelectElement),
    captionDensitySelect: query(root, 'select[name="captionDensity"]', HTMLSelectElement),
    captionContrastThemeSelect: query(root, 'select[name="captionContrastTheme"]', HTMLSelectElement),
    overlayVisibleInput: query(root, 'input[name="overlayVisible"]', HTMLInputElement),
    showRomanizationInputs: queryAll<HTMLInputElement>(root, 'input[name="showRomanization"]'),
    showTranslationInput: query(root, 'input[name="showTranslation"]', HTMLInputElement),
    showGlossInput: query(root, 'input[name="showGloss"]', HTMLInputElement),
    blurSourceWordsInput: query(root, 'input[name="blurSourceWords"]', HTMLInputElement),
    blurRomanizationInput: query(root, 'input[name="blurRomanization"]', HTMLInputElement),
    blurTranslationInput: query(root, 'input[name="blurTranslation"]', HTMLInputElement),
    pauseOnWordHoverInput: query(root, 'input[name="pauseOnWordHover"]', HTMLInputElement),
    keyboardShortcutsEnabledInput: query(root, 'input[name="keyboardShortcutsEnabled"]', HTMLInputElement),
    fullTrackEnrichmentInput: query(root, 'input[name="fullTrackEnrichment"]', HTMLInputElement),
    timingOffsetRangeInput: query(root, 'input[name="subtitleTimingOffsetSeconds"]', HTMLInputElement),
    timingOffsetNumberInput: query(root, 'input[name="subtitleTimingOffsetNumber"]', HTMLInputElement),
    timingOffsetOutput: query(root, '[data-timing-offset]', HTMLOutputElement),
    progressContainer: query(root, '[data-progress]', HTMLElement),
    progressLabel: query(root, '[data-progress-label]', HTMLElement),
    progressPercent: query(root, '[data-progress-percent]', HTMLElement),
    progressBar: query(root, '[data-progress-bar]', HTMLElement),
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
    accountLoginForm: query(root, '[data-account-login-form]', HTMLFormElement),
    accountEmailInput: query(root, 'input[name="accountEmail"]', HTMLInputElement),
    accountPasswordInput: query(root, 'input[name="accountPassword"]', HTMLInputElement),
    accountLoginButton: query(root, '[data-action="login"]', HTMLButtonElement),
    logoutButton: query(root, '[data-action="logout"]', HTMLButtonElement),
    accountFeedback: query(root, '[data-account-feedback]', HTMLElement),
    featureList: query(root, '[data-feature-list]', HTMLElement),
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

import { escapeHtml } from '../../../utils/html';
import { languageSearchText, type LanguageOption } from '../../../utils/languages';

export function renderLanguagePicker(options: {
  options: readonly LanguageOption[];
  query: string;
  selectedCode: string | undefined;
  selectedContainer: HTMLElement;
  listContainer: HTMLElement;
  disabled: boolean;
}): void {
  const selectedLanguage = options.options.find((language) => language.code === options.selectedCode);
  const normalizedQuery = options.query.trim().toLowerCase();
  const visibleLanguages =
    normalizedQuery === ''
      ? options.options
      : options.options.filter((language) => languageSearchText(language).includes(normalizedQuery));

  options.selectedContainer.innerHTML = selectedLanguage
    ? selectedLanguageSummary(selectedLanguage)
    : '<span class="muted">No language selected</span>';
  options.listContainer.innerHTML =
    visibleLanguages.length === 0
      ? '<p class="muted empty-state">No languages match that search.</p>'
      : visibleLanguages
          .map((language) => languageOptionButton(language, language.code === options.selectedCode, options.disabled))
          .join('');
}

function selectedLanguageSummary(language: LanguageOption): string {
  return `
    <span>${escapeHtml(language.label)}</span>
    <span class="language-code">${escapeHtml(language.code)}</span>
  `;
}

function languageOptionButton(language: LanguageOption, selected: boolean, disabled: boolean): string {
  return `
    <button
      type="button"
      class="language-option${selected ? ' selected' : ''}"
      data-language-code="${escapeHtml(language.code)}"
      role="option"
      aria-selected="${selected ? 'true' : 'false'}"
      ${disabled ? 'disabled' : ''}
    >
      <span class="language-option-main">
        <span>${escapeHtml(language.label)}</span>
        <span class="language-code">${escapeHtml(language.code)}</span>
      </span>
    </button>
  `;
}

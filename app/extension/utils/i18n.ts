import locales from '../../../packages/localization/locales.json';
import es from '../../../packages/localization/extension/es.json';
import pt from '../../../packages/localization/extension/pt-BR.json';
import fr from '../../../packages/localization/extension/fr.json';
import de from '../../../packages/localization/extension/de.json';
import ja from '../../../packages/localization/extension/ja.json';
import ko from '../../../packages/localization/extension/ko.json';
import id from '../../../packages/localization/extension/id.json';
import zh from '../../../packages/localization/extension/zh-CN.json';

export const INTERFACE_LOCALES = locales;
export type InterfaceLocale = keyof typeof locales;
const messages: Record<string, Record<string, string>> = { es, 'pt-BR': pt, fr, de, ja, ko, id, 'zh-CN': zh };
let locale: InterfaceLocale = 'en';

export function isInterfaceLocale(value: unknown): value is InterfaceLocale {
  return typeof value === 'string' && Object.hasOwn(locales, value);
}

export function resolveInterfaceLocale(value: unknown, languages: readonly string[] = globalThis.navigator?.languages ?? []): InterfaceLocale {
  if (isInterfaceLocale(value)) return value;
  for (const language of languages) {
    const base = language.toLowerCase().split(/[-_]/)[0];
    const match = Object.keys(locales).find((supported) => supported.toLowerCase().split('-')[0] === base);
    if (match) return match as InterfaceLocale;
  }
  return 'en';
}

export function setInterfaceLocale(value: unknown): void {
  locale = resolveInterfaceLocale(value);
}

export function interfaceLocale(): InterfaceLocale { return locale; }

export function t(message: string, values: Record<string, string | number> = {}): string {
  const catalog = messages[locale];
  const translated = catalog && Object.hasOwn(catalog, message) ? catalog[message] ?? message : message;
  return translated.replace(/\{(\w+)\}/g, (placeholder, key: string) => Object.hasOwn(values, key) ? String(values[key]) : placeholder);
}

/** Only marked interface copy is translated. Video titles and learning content stay untouched. */
export function localizeDocument(root: Document): void {
  root.documentElement.lang = locale;
  for (const element of root.querySelectorAll<HTMLElement>('[data-i18n]')) {
    const text = element.textContent ?? '';
    element.textContent = (text.match(/^\s*/)?.[0] ?? '') + t(element.dataset.i18n!) + (text.match(/\s*$/)?.[0] ?? '');
  }
  for (const attribute of ['title', 'aria-label', 'placeholder']) {
    for (const element of root.querySelectorAll(`[data-i18n-${attribute}]`)) {
      element.setAttribute(attribute, t(element.getAttribute(`data-i18n-${attribute}`)!));
    }
  }
}

import { afterEach, expect, it } from 'vitest';
import { JSDOM } from 'jsdom';
import { INTERFACE_LOCALES, localizeDocument, resolveInterfaceLocale, setInterfaceLocale, t } from '../utils/i18n';
import { createExtensionSettingsFromPartial } from '../utils/settings-model';
import { accountBillingLinkHtml } from '../entrypoints/sidepanel/render/account';

afterEach(() => setInterfaceLocale('en'));

it('matches browser languages in preference order and validates saved locale values', () => {
  expect(resolveInterfaceLocale('auto', ['pl-PL', 'pt-PT', 'de'])).toBe('pt-BR');
  expect(resolveInterfaceLocale('auto', ['zh-Hans-SG'])).toBe('zh-CN');
  expect(resolveInterfaceLocale('ja', ['es'])).toBe('ja');
  expect(resolveInterfaceLocale('__proto__', ['invalid'])).toBe('en');
  expect(createExtensionSettingsFromPartial({ interfaceLocale: 'ja', sourceLanguage: 'kor', targetLanguage: 'spa' }))
    .toMatchObject({ interfaceLocale: 'ja', sourceLanguage: 'kor', targetLanguage: 'spa' });
});

it('translates marked interface copy and attributes without changing learning content or inputs', () => {
  const dom = new JSDOM('<button data-i18n="Sign in">Sign in</button><input value="user draft" data-i18n-placeholder="Search transcript…"><p>Sign in</p><span data-i18n="Sign in"> Sign in </span>');
  setInterfaceLocale('es');
  localizeDocument(dom.window.document);
  expect(dom.window.document.documentElement.lang).toBe('es');
  expect(dom.window.document.querySelector('button')!.textContent).toBe(t('Sign in'));
  expect(dom.window.document.querySelector('p')!.textContent).toBe('Sign in');
  expect(dom.window.document.querySelector('input')!.value).toBe('user draft');
  expect(dom.window.document.querySelector('span')!.textContent).toBe(` ${t('Sign in')} `);
  setInterfaceLocale('ja');
  localizeDocument(dom.window.document);
  expect(dom.window.document.querySelector('button')!.textContent).toBe(t('Sign in'));
  expect(dom.window.document.querySelector('span')!.textContent).toBe(` ${t('Sign in')} `);
  expect(accountBillingLinkHtml('https://example.test/v1')).toContain('/dashboard?lang=ja');
  dom.window.close();
});

it('renders all supported languages and preserves interpolation and English fallback', () => {
  for (const locale of Object.keys(INTERFACE_LOCALES)) {
    setInterfaceLocale(locale);
    expect(t('Interface language').length).toBeGreaterThan(0);
    if (locale !== 'en') expect(t('Sign in')).not.toBe('Sign in');
    expect(t('Copy cue {number}', { number: 12 })).toContain('12');
    expect(t('Unknown future message')).toBe('Unknown future message');
    expect(t('__proto__')).toBe('__proto__');
  }
});

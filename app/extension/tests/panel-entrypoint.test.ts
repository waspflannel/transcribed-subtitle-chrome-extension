import { afterEach, expect, it, vi } from 'vitest';
import { JSDOM } from 'jsdom';
import markup from '../entrypoints/sidepanel/index.html?raw';

const mocks = vi.hoisted(() => ({
  sendMessage: vi.fn(() => new Promise(() => {})),
  connect: vi.fn(() => ({ onDisconnect: { addListener: vi.fn() } })),
  messages: vi.fn(),
  activated: vi.fn(),
  updated: vi.fn(),
}));
vi.mock('wxt/browser', () => ({ browser: {
  runtime: { sendMessage: mocks.sendMessage, connect: mocks.connect, onMessage: { addListener: mocks.messages } },
  windows: { getCurrent: vi.fn(async () => ({ id: 7 })) },
  tabs: { onActivated: { addListener: mocks.activated }, onUpdated: { addListener: mocks.updated } },
} }));

afterEach(() => {
  vi.clearAllTimers();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

it('imports the real panel entrypoint and attaches synchronization without a lexical startup error', async () => {
  vi.useFakeTimers();
  const dom = new JSDOM(markup);
  vi.stubGlobal('window', dom.window);
  vi.stubGlobal('document', dom.window.document);
  vi.stubGlobal('Element', dom.window.Element);
  vi.stubGlobal('HTMLElement', dom.window.HTMLElement);
  await import('../entrypoints/sidepanel/main');
  await Promise.resolve();
  expect(mocks.connect).toHaveBeenCalledOnce();
  expect(mocks.messages).toHaveBeenCalledOnce();
  expect(mocks.activated).toHaveBeenCalledOnce();
  expect(mocks.updated).toHaveBeenCalledOnce();
  expect(vi.getTimerCount()).toBe(1);
  dom.window.close();
});

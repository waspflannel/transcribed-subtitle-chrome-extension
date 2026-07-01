import { describe, expect, it, vi } from 'vitest';

import { PanelPortConnector, PanelPortRegistry, type PanelPort, type PanelPortConnectFn } from '../utils/panel-port-registry';

describe('PanelPortRegistry', () => {
  it('reports no open panel when empty', () => {
    const registry = new PanelPortRegistry();
    expect(registry.hasOpenPanel()).toBe(false);
  });

  it('reports an open panel after a port is added', () => {
    const registry = new PanelPortRegistry();
    const port = {};
    registry.add(port);
    expect(registry.hasOpenPanel()).toBe(true);
  });

  it('reports no open panel after the last port is removed', () => {
    const registry = new PanelPortRegistry();
    const port = {};
    registry.add(port);
    registry.remove(port);
    expect(registry.hasOpenPanel()).toBe(false);
  });

  it('tracks multiple panels and only reports closed when all are gone', () => {
    const registry = new PanelPortRegistry();
    const portA = {};
    const portB = {};
    registry.add(portA);
    registry.add(portB);
    expect(registry.hasOpenPanel()).toBe(true);
    registry.remove(portA);
    expect(registry.hasOpenPanel()).toBe(true);
    registry.remove(portB);
    expect(registry.hasOpenPanel()).toBe(false);
  });

  it('removing a port that was never added is a no-op', () => {
    const registry = new PanelPortRegistry();
    registry.add({});
    registry.remove({});
    expect(registry.hasOpenPanel()).toBe(true);
  });

  it('clear removes all ports', () => {
    const registry = new PanelPortRegistry();
    registry.add({});
    registry.add({});
    registry.clear();
    expect(registry.hasOpenPanel()).toBe(false);
  });
});

function createFakePort(): { port: PanelPort; disconnect: () => void } {
  const listeners: Array<() => void> = [];
  return {
    port: {
      onDisconnect: {
        addListener: (listener: () => void) => listeners.push(listener),
      },
    },
    disconnect: () => {
      for (const listener of listeners) listener();
    },
  };
}

describe('PanelPortConnector', () => {
  it('connects on connect() call', () => {
    const connectFn = vi.fn<(name: 'panel') => PanelPort>();
    connectFn.mockImplementation(() => createFakePort().port);
    const connector = new PanelPortConnector(connectFn);
    connector.connect();
    expect(connectFn).toHaveBeenCalledTimes(1);
    expect(connectFn).toHaveBeenCalledWith('panel');
  });

  it('reconnects after the port disconnects', () => {
    const fakePorts: Array<{ port: PanelPort; disconnect: () => void }> = [];
    const connectFn = vi.fn<PanelPortConnectFn>();
    connectFn.mockImplementation(() => {
      const entry = createFakePort();
      fakePorts.push(entry);
      return entry.port;
    });
    const schedule = vi.fn<(callback: () => void, delay: number) => void>();
    const connector = new PanelPortConnector(connectFn, { schedule, reconnectDelayMs: 1000 });
    connector.connect();

    expect(connectFn).toHaveBeenCalledTimes(1);
    fakePorts[0].disconnect();

    expect(schedule).toHaveBeenCalledTimes(1);
    expect(schedule).toHaveBeenCalledWith(expect.any(Function), 1000);
  });

  it('reconnects when the initial connect throws', () => {
    let attempts = 0;
    const connectFn: PanelPortConnectFn = () => {
      attempts += 1;
      if (attempts === 1) throw new Error('background unavailable');
      return createFakePort().port;
    };
    const schedule = vi.fn<(callback: () => void, delay: number) => void>();
    const connector = new PanelPortConnector(connectFn, { schedule, reconnectDelayMs: 500 });
    connector.connect();

    expect(schedule).toHaveBeenCalledTimes(1);
    expect(schedule).toHaveBeenCalledWith(expect.any(Function), 500);
  });

  it('stops reconnecting after disconnect() is called', () => {
    const fakePorts: Array<{ port: PanelPort; disconnect: () => void }> = [];
    const connectFn: PanelPortConnectFn = () => {
      const entry = createFakePort();
      fakePorts.push(entry);
      return entry.port;
    };
    const schedule = vi.fn<(callback: () => void, delay: number) => void>();
    const connector = new PanelPortConnector(connectFn, { schedule, reconnectDelayMs: 1000 });
    connector.connect();

    connector.disconnect();
    fakePorts[0].disconnect();

    expect(schedule).not.toHaveBeenCalled();
  });

  it('keeps reconnecting across multiple disconnect cycles', () => {
    const fakePorts: Array<{ port: PanelPort; disconnect: () => void }> = [];
    const connectFn = vi.fn<PanelPortConnectFn>();
    connectFn.mockImplementation(() => {
      const entry = createFakePort();
      fakePorts.push(entry);
      return entry.port;
    });
    const scheduledCallbacks: Array<() => void> = [];
    const schedule = vi.fn<(callback: () => void, delay: number) => void>();
    schedule.mockImplementation((callback) => {
      scheduledCallbacks.push(callback);
    });
    const connector = new PanelPortConnector(connectFn, { schedule, reconnectDelayMs: 100 });
    connector.connect();

    fakePorts[0].disconnect();
    expect(scheduledCallbacks).toHaveLength(1);
    scheduledCallbacks[0]();
    expect(connectFn).toHaveBeenCalledTimes(2);

    fakePorts[1].disconnect();
    expect(scheduledCallbacks).toHaveLength(2);
    scheduledCallbacks[1]();
    expect(connectFn).toHaveBeenCalledTimes(3);
  });
});

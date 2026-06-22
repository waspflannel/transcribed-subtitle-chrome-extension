import { describe, expect, it } from 'vitest';

import { PanelPortRegistry } from '../utils/panel-port-registry';

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

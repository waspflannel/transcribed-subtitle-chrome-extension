export class PanelPortRegistry {
  private readonly ports = new Set<unknown>();

  add(port: unknown): void {
    this.ports.add(port);
  }

  remove(port: unknown): void {
    this.ports.delete(port);
  }

  hasOpenPanel(): boolean {
    return this.ports.size > 0;
  }

  clear(): void {
    this.ports.clear();
  }
}

export interface PanelPort {
  onDisconnect: {
    addListener(listener: () => void): void;
  };
}

export interface PanelPortConnectFn {
  (name: 'panel'): PanelPort;
}

export class PanelPortConnector {
  private readonly reconnectDelayMs: number;
  private readonly connectFn: PanelPortConnectFn;
  private readonly schedule: (callback: () => void, delay: number) => void;
  private stopped = false;

  constructor(
    connectFn: PanelPortConnectFn,
    options: {
      reconnectDelayMs?: number;
      schedule?: (callback: () => void, delay: number) => void;
    } = {},
  ) {
    this.connectFn = connectFn;
    this.reconnectDelayMs = options.reconnectDelayMs ?? 1000;
    this.schedule = options.schedule ?? ((callback, delay) => setTimeout(callback, delay));
  }

  connect(): void {
    if (this.stopped) return;
    try {
      const port = this.connectFn('panel');
      port.onDisconnect.addListener(() => {
        if (this.stopped) return;
        this.schedule(() => this.connect(), this.reconnectDelayMs);
      });
    } catch {
      if (this.stopped) return;
      this.schedule(() => this.connect(), this.reconnectDelayMs);
    }
  }

  disconnect(): void {
    this.stopped = true;
  }
}

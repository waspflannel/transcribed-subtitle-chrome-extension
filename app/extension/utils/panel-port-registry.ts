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

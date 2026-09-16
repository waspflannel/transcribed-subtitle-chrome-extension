export function setupTabs(tabButtons: readonly HTMLButtonElement[], panels: readonly HTMLElement[]): void {
  const activeTab = tabButtons.find((button) => button.classList.contains('active'))?.dataset.tab
    ?? tabButtons[0]?.dataset.tab
    ?? 'watch';

  showTab(tabButtons, panels, activeTab);

  for (const button of tabButtons) {
    button.addEventListener('click', () => showTab(tabButtons, panels, button.dataset.tab ?? 'watch'));
    button.addEventListener('keydown', (event) => handleTabKeydown(event, tabButtons, panels));
  }
}

export function showTab(
  tabButtons: readonly HTMLButtonElement[],
  panels: readonly HTMLElement[],
  tabName: string,
): void {
  for (const button of tabButtons) {
    const active = button.dataset.tab === tabName;

    button.classList.toggle('active', active);
    button.setAttribute('aria-selected', active ? 'true' : 'false');
    button.tabIndex = active ? 0 : -1;
  }

  for (const panel of panels) {
    const active = panel.dataset.panel === tabName;

    panel.classList.toggle('active', active);
    panel.hidden = !active;
  }
}

function handleTabKeydown(
  event: KeyboardEvent,
  tabButtons: readonly HTMLButtonElement[],
  panels: readonly HTMLElement[],
): void {
  const currentIndex = tabButtons.findIndex((button) => button === event.currentTarget);

  if (currentIndex < 0) {
    return;
  }

  const nextIndex = nextTabIndex(event.key, currentIndex, tabButtons.length);

  if (nextIndex === null) {
    return;
  }

  const nextButton = tabButtons[nextIndex];
  if (!nextButton) return;

  event.preventDefault();
  nextButton.focus();
  showTab(tabButtons, panels, nextButton.dataset.tab ?? 'watch');
}

function nextTabIndex(key: string, currentIndex: number, tabCount: number): number | null {
  switch (key) {
    case 'ArrowRight':
    case 'ArrowDown':
      return (currentIndex + 1) % tabCount;

    case 'ArrowLeft':
    case 'ArrowUp':
      return (currentIndex - 1 + tabCount) % tabCount;

    case 'Home':
      return 0;

    case 'End':
      return tabCount - 1;

    default:
      return null;
  }
}

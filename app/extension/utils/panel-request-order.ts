export interface PanelRequestOrder {
  mutationVersion: number;
  mutationsInFlight: number;
}

export function panelRequestOrder(): PanelRequestOrder {
  return { mutationVersion: 0, mutationsInFlight: 0 };
}

export function beginPanelRequest(
  state: PanelRequestOrder,
  kind: 'normal' | 'mutation',
): { state: PanelRequestOrder; version: number; startedDuringMutation: boolean } {
  if (kind === 'mutation') {
    return {
      state: {
        mutationVersion: state.mutationVersion + 1,
        mutationsInFlight: state.mutationsInFlight + 1,
      },
      version: state.mutationVersion + 1,
      startedDuringMutation: false,
    };
  }

  return {
    state,
    version: state.mutationVersion,
    startedDuringMutation: state.mutationsInFlight > 0,
  };
}

export function canApplyPanelResponse(
  state: PanelRequestOrder,
  kind: 'normal' | 'mutation',
  version: number,
  startedDuringMutation: boolean,
): boolean {
  return kind === 'mutation'
    ? version === state.mutationVersion
    : !startedDuringMutation && state.mutationsInFlight === 0 && version === state.mutationVersion;
}

export function finishPanelRequest(state: PanelRequestOrder): PanelRequestOrder {
  return { ...state, mutationsInFlight: Math.max(0, state.mutationsInFlight - 1) };
}

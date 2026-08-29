export const OPERATIONS_GAME_OPTIONS = [
  { id: "all", label: "All games", shortLabel: "All games" },
  { id: "blackred", label: "BlackRed", shortLabel: "BlackRed" },
  { id: "heritage", label: "Heritage", shortLabel: "Heritage" },
] as const;

export type OperationsGameScope = (typeof OPERATIONS_GAME_OPTIONS)[number]["id"];

export function isOperationsGameScope(value: string): value is OperationsGameScope {
  return OPERATIONS_GAME_OPTIONS.some((game) => game.id === value);
}

export function operationsGameLabel(scope: OperationsGameScope) {
  return OPERATIONS_GAME_OPTIONS.find((game) => game.id === scope)?.label ?? "All games";
}

"use client";

import { OPERATIONS_GAME_OPTIONS, isOperationsGameScope } from "@/components/operations/operations-games";
import { useOperationsUrlFilters } from "@/components/operations/useOperationsUrlFilters";
import styles from "./GameScopeSelector.module.css";

const GAME_SCOPE_DEFAULTS = { game: "all" };

export function GameScopeSelector() {
  const { filters, updateFilter } = useOperationsUrlFilters(GAME_SCOPE_DEFAULTS);
  const selectedGame = isOperationsGameScope(filters.game) ? filters.game : "all";

  return (
    <div className={styles.field}>
      <label htmlFor="operations-game-scope">Game</label>
      <select
        id="operations-game-scope"
        value={selectedGame}
        onChange={(event) => updateFilter("game", event.target.value)}
        aria-describedby="operations-game-scope-hint"
      >
        {OPERATIONS_GAME_OPTIONS.map((game) => <option key={game.id} value={game.id}>{game.label}</option>)}
      </select>
      <span className="sr-only" id="operations-game-scope-hint">Filters game-specific dashboards and management pages.</span>
    </div>
  );
}

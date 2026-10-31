const LABELS: Record<string, string> = {
  prize_table_publish: "Prize table publication",
  game_economics_config_publish: "Game funding rules",
  promotional_config_publish: "Promotion rules",
  manual_adjustment: "Wallet adjustment",
  institution_user: "Staff member",
};

/** Keep service codes intact in requests; use readable labels in the workspace. */
export function operationsLabel(value: string) {
  return LABELS[value] ?? value.replaceAll("_", " ").replaceAll(".", " ").replace(/\b\w/g, letter => letter.toUpperCase());
}

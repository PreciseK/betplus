const TOOLS = [
  ["Deposit and stake limits", "Lower limits take effect immediately"],
  ["Session reality checks", "Time, total staked, and net position"],
  ["Cool-off periods", "24 hours, 7 days, or 30 days"],
  ["Self-exclusion", "Across every Betplus game"],
] as const;

export function SafePlaySection() {
  return (
    <section className="safe-section" id="safe-play" aria-labelledby="safe-title">
      <div className="safe-copy reveal-on-scroll">
        <p className="eyebrow eyebrow-dark">Your play. Your limits.</p>
        <h2 id="safe-title">Control belongs<br />with you.</h2>
        <p>See your net position, set limits across every game, take a break, or self-exclude. These tools stay easy to find, and withdrawals remain available when required.</p>
        <a className="button button-dark" href="/safe-play">Explore safe-play tools</a>
      </div>
      <div className="safe-tools reveal-on-scroll" aria-label="Available safe-play tools">
        {TOOLS.map(([title, body], index) => (
          <div key={title}>
            <span>{String(index + 1).padStart(2, "0")}</span>
            <strong>{title}</strong>
            <small>{body}</small>
          </div>
        ))}
      </div>
    </section>
  );
}

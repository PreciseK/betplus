const TOOLS = [
  ["Deposit and stake limits", "Lower limits take effect immediately. Increases take effect after 24 hours."],
  ["Net position", "Review what you have won minus what you have staked."],
  ["Take a break", "Pause play and deposits for a selected period."],
  ["Self-exclusion", "Exclude yourself across Betplus games for your chosen period."],
];

export function SafePlaySection() {
  return (
    <section className="safe-section" id="safe-play" aria-labelledby="safe-title">
      <div className="safe-copy"><p className="eyebrow">Play responsibly</p><h2 id="safe-title">Set your limits.</h2><p>Manage your limits, take a break or self-exclude from your account. Eligible withdrawals remain available.</p><a className="arrow-link" href="/account">View account controls <span aria-hidden="true">↗</span></a></div>
      <dl className="safe-tools">{TOOLS.map(([title, body]) => <div key={title}><dt>{title}</dt><dd>{body}</dd></div>)}</dl>
    </section>
  );
}

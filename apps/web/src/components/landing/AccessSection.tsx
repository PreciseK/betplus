const CHANNELS = [
  ["Web", "Responsive on phones and desktops, with no app install required."],
  ["Android", "A focused app experience with ticket and payout updates."],
  ["USSD", "Play from a feature phone and receive every result again by SMS."],
] as const;

export function AccessSection() {
  return (
    <section className="access-section" id="access" aria-labelledby="access-title">
      <div className="access-intro reveal-on-scroll">
        <p className="eyebrow">Made for how Nigeria connects</p>
        <h2 id="access-title">Use data.<br />Or just dial.</h2>
        <p>The same account, wallet, odds, and protections follow you across every available channel.</p>
      </div>
      <div className="access-list">
        {CHANNELS.map(([title, body], index) => (
          <article className="reveal-on-scroll" key={title}>
            <span className="access-number">{String(index + 1).padStart(2, "0")}</span>
            <div><h3>{title}</h3><p>{body}</p></div>
            <span className="availability">At launch</span>
          </article>
        ))}
      </div>
    </section>
  );
}

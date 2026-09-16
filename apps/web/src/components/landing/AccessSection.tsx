const CHANNELS = [
  [
    "USSD (*7006#)",
    "Instant keypad play on any phone across MTN, Airtel, Glo, and 9mobile. Zero internet needed, auto OPay balance funding, and guaranteed SMS receipts.",
    "Live now",
  ],
  [
    "Web Browser",
    "Responsive on mobile and desktop browsers with no app download required. Live crash multiplayer and interactive game boards.",
    "Live now",
  ],
  [
    "Android App",
    "A focused mobile experience with push notifications, biometric sign-in, and instant payout alerts.",
    "At launch",
  ],
] as const;

export function AccessSection() {
  return (
    <section className="access-section" id="access" aria-labelledby="access-title">
      <div className="access-intro reveal-on-scroll">
        <p className="eyebrow">Made for how Nigeria connects</p>
        <h2 id="access-title">Use data.<br />Or just dial *7006#.</h2>
        <p>The same account, wallet, odds, and protections follow you across every channel.</p>

        <div className="ussd-featured-card">
          <div className="ussd-card-header">
            <span className="ussd-card-badge">Instant USSD Shortcode</span>
            <span className="ussd-network-tags">MTN · Airtel · Glo · 9mobile</span>
          </div>
          <div className="ussd-dial-highlight">*7006#</div>
          <p className="ussd-card-summary">
            No internet? Dial <strong>*7006#</strong> from your registered OPay phone number to play BlackRed, Heritage, or Caged instantly.
          </p>
          <div className="ussd-perks-grid">
            <div className="ussd-perk">
              <strong>Direct OPay Funding</strong>
              <p>Play balance at zero? We withdraw the stake straight from your OPay balance with zero hassle.</p>
            </div>
            <div className="ussd-perk">
              <strong>Guaranteed SMS</strong>
              <p>Every ticket result is delivered to your SMS inbox so a network drop never costs you your win.</p>
            </div>
          </div>
        </div>
      </div>

      <div className="access-list">
        {CHANNELS.map(([title, body, availability], index) => (
          <article className="reveal-on-scroll" key={title}>
            <span className="access-number">{String(index + 1).padStart(2, "0")}</span>
            <div>
              <h3>{title}</h3>
              <p>{body}</p>
            </div>
            <span className={`availability ${availability === 'Live now' ? 'availability-live' : ''}`}>
              {availability}
            </span>
          </article>
        ))}
      </div>
    </section>
  );
}

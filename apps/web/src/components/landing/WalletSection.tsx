export function WalletSection() {
  return (
    <section className="wallet-section" aria-labelledby="wallet-title">
      <div className="wallet-story reveal-on-scroll">
        <p className="eyebrow eyebrow-dark">Money without mystery</p>
        <h2 id="wallet-title">One wallet.<br />Two clear balances.</h2>
        <p>Deposits enter your Play Balance. Settled winnings enter your Winnings Balance. Betplus labels both, so you always know what is available and why.</p>
        <a className="arrow-link arrow-link-dark" href="/help/wallet">
          Understand your balances
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 12h14m-5-5 5 5-5 5" /></svg>
        </a>
      </div>
      <figure className="receipt reveal-on-scroll">
        <figcaption>Example win receipt</figcaption>
        <div className="receipt-status"><span aria-hidden="true">✓</span> Settled</div>
        <dl>
          <div><dt>Gross prize</dt><dd>₦8,000</dd></div>
          <div><dt>Tax withheld</dt><dd>−₦350</dd></div>
          <div className="receipt-total"><dt>Net credited</dt><dd>₦7,650</dd></div>
        </dl>
        <div className="receipt-meta"><span>Winnings Balance</span><span>Ticket BP•••4821</span></div>
        <p>Illustrative amounts only. Actual odds, prize, and tax are shown before and after each play.</p>
      </figure>
    </section>
  );
}

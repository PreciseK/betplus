export function WalletSection() {
  return (
    <section className="wallet-section" aria-labelledby="wallet-title">
      <div className="wallet-story"><p className="eyebrow">Your wallet</p><h2 id="wallet-title">Know your balance.</h2><p>Deposits and settled winnings are shown separately. Check withdrawal eligibility in your wallet.</p><a className="arrow-link" href="/wallet">View wallet <span aria-hidden="true">↗</span></a></div>
      <dl className="balance-explainer">
        <div><dt><span aria-hidden="true">01</span> Play Balance</dt><dd>Deposited funds available to stake. Turnover requirements apply to withdrawals.</dd></div>
        <div><dt><span aria-hidden="true">02</span> Winnings Balance</dt><dd>Settled winnings available for withdrawal, subject to verification.</dd></div>
      </dl>
    </section>
  );
}

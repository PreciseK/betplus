export function FinalCta() {
  return (
    <section className="start-section" id="start" aria-labelledby="start-title">
      <div className="start-glow" aria-hidden="true" />
      <p className="eyebrow reveal-on-scroll">One account. Every game.</p>
      <h2 id="start-title" className="reveal-on-scroll">Ready when<br />you are.</h2>
      <p className="reveal-on-scroll">You’ll need an OPay account, your phone, and your NIN to complete player-protection checks.</p>
      <div className="start-actions reveal-on-scroll">
        <a className="button button-primary" href="/register">Create your account</a>
        <a className="button button-quiet" href="/help/getting-started">What you’ll need</a>
      </div>
      <p className="age-note reveal-on-scroll"><strong>18+ only.</strong> Please play within your limits.</p>
    </section>
  );
}

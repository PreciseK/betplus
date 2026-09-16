export function FinalCta() {
  return (
    <section className="start-section" id="start" aria-labelledby="start-title">
      <div className="start-glow" aria-hidden="true" />
      <p className="eyebrow reveal-on-scroll">Three certified games. Web &amp; USSD *7006#.</p>
      <h2 id="start-title" className="reveal-on-scroll">Ready when<br />you are.</h2>
      <p className="reveal-on-scroll">Play online with your OPay account and NIN, or simply dial <strong>*7006#</strong> from any phone for instant play with automatic direct OPay funding.</p>
      <div className="start-actions reveal-on-scroll">
        <a className="button button-primary" href="/register">Create account online</a>
        <a className="button button-quiet" href="tel:*7006%23">Dial *7006# now</a>
      </div>
      <p className="age-note reveal-on-scroll"><strong>18+ only.</strong> Nigeria · Please play within your limits.</p>
    </section>
  );
}

export function AccessSection() {
  return (
    <section className="access-section" id="access" aria-labelledby="access-title">
      <div><p className="eyebrow">Ways to play</p><h2 id="access-title">Online or on your phone.</h2></div>
      <div className="access-list">
        <article><h3>Web</h3><p>Open Betplus in your mobile or desktop browser. No download required.</p><a className="arrow-link" href="/games">Browse games <span aria-hidden="true">↗</span></a></article>
        <article><h3>USSD <span className="dial-code">*7006#</span></h3><p>Dial from your registered OPay phone number to see available USSD games. Doors is played on the web.</p><a className="arrow-link" href="tel:*7006%23">Dial *7006# <span aria-hidden="true">↗</span></a></article>
      </div>
    </section>
  );
}

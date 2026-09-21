export function Hero() {
  return (
    <section className="hero" id="top" aria-labelledby="hero-title">
      <div className="hero-glow" aria-hidden="true" />
      <div className="hero-rings" aria-hidden="true"><span /><span /><span /></div>

      <div className="hero-copy t-stagger">
        <p className="eyebrow t-stagger-line t-stagger-line--1">Web, Android &amp; USSD *7006#</p>
        <h1 id="hero-title" className="t-stagger-line t-stagger-line--2">
          <span className="hero-promise">Your Move.<br />Your Moment.<br />Your Naira.</span>
        </h1>
        <p className="hero-summary t-stagger-line t-stagger-line--3">
          No waiting, no wondering, and no lost tickets. From instant OPay direct funding to certified payouts, play BlackRed, Heritage, or Caged online or dial <strong>*7006#</strong> with zero data.
        </p>
        <div className="hero-actions t-stagger-line t-stagger-line--4">
          <a className="button button-primary" href="#games">Explore the games</a>
          <a className="button button-quiet" href="#access">
            Play via USSD (*7006#)
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6" /></svg>
          </a>
        </div>
        <p className="age-note t-stagger-line t-stagger-line--5">
          <strong>18+ only.</strong> Nigeria · OPay account required · Terms apply
        </p>
      </div>

      <div className="hero-art reveal reveal-delay-2" aria-hidden="true">
        <div className="hero-logo-halo" />
        <img className="hero-logo" src="/assets/hero-community.png" alt="Betplus Community" />
        <div className="orbit orbit-blackred"><span>B / R</span><small>BLACKRED</small></div>
        <div className="orbit orbit-heritage"><span>05 / 90</span><small>HERITAGE</small></div>
        <div className="orbit orbit-caged"><span>1-5 BIRDS</span><small>CAGED CRASH</small></div>
        <div className="orbit orbit-ussd"><span>*7006#</span><small>USSD PLAY</small></div>
        <div className="orbit orbit-opay"><span>₦</span><small>DIRECT OPAY</small></div>
      </div>

      <a className="scroll-cue" href="#games" aria-label="Scroll to games">
        <span>Discover</span>
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6" /></svg>
      </a>
    </section>
  );
}

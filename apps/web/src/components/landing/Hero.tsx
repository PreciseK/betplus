export function Hero() {
  return (
    <section className="hero" id="top" aria-labelledby="hero-title">
      <div className="hero-glow" aria-hidden="true" />
      <div className="hero-rings" aria-hidden="true"><span /><span /><span /></div>

      <div className="hero-copy t-stagger">
        <p className="eyebrow t-stagger-line t-stagger-line--1">One account across every game</p>
        <h1 id="hero-title" className="t-stagger-line t-stagger-line--2">
          <span className="hero-brand">Betplus</span>
          <span className="hero-promise">Two games.<br />One wallet.</span>
        </h1>
        <p className="hero-summary t-stagger-line t-stagger-line--3">
          Choose your game, know your stake, and follow every Naira from play to payout.
        </p>
        <div className="hero-actions t-stagger-line t-stagger-line--4">
          <a className="button button-primary" href="#games">Explore the games</a>
          <a className="button button-quiet" href="#how-it-works">
            How it works
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
        <div className="orbit orbit-opay"><span>₦</span><small>ONE WALLET</small></div>
      </div>

      <a className="scroll-cue" href="#games" aria-label="Scroll to games">
        <span>Discover</span>
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6" /></svg>
      </a>
    </section>
  );
}

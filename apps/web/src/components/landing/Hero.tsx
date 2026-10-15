import Image from "next/image";

export function Hero() {
  return (
    <section className="hero" id="top" aria-labelledby="hero-title">
      <div className="hero-media" aria-hidden="true">
        <Image src="/assets/hero-community.png" alt="" width={811} height={1024} priority unoptimized />
      </div>
      <div className="hero-copy">
        <p className="eyebrow">Betplus · Online & USSD</p>
        <h1 id="hero-title"><span>Your Move.</span>{" "}<span>Your Moment.</span>{" "}<span>Your Win.</span></h1>
        <p className="hero-summary">Choose your game, make your call and watch it unfold. Explore the games on Betplus, with your wallet, activity and play limits in one place.</p>
        <div className="hero-actions">
          <a className="button button-primary" href="/register">Create account <span aria-hidden="true">↗</span></a>
          <a className="hero-game-link" href="#games">See the games <span aria-hidden="true">↓</span></a>
        </div>
        <p className="age-note">18+ only · OPay account required · Terms apply</p>
      </div>
    </section>
  );
}

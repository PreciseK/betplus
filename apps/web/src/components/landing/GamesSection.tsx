export function GamesSection() {
  return (
    <section className="games-section" id="games" aria-labelledby="games-title">
      <div className="section-heading reveal-on-scroll">
        <p className="eyebrow eyebrow-dark">Choose your kind of play</p>
        <h2 id="games-title">Different games.<br />The same clear rules.</h2>
        <p>One profile, shared balances, and one activity history—whichever game you choose.</p>
      </div>

      <div className="game-choice-grid" aria-label="Choose a Betplus game">
        <article className="game-band game-band-blackred">
          <div className="game-copy">
            <p className="game-index">01 / Instant prediction</p>
            <h3>BlackRed</h3>
            <p>Call Black or Red across one to five cards. See the real odds and possible return before you confirm your stake.</p>
            <ul className="plain-list" aria-label="BlackRed features">
              <li>Quick, fixed-odds rounds</li>
              <li>Text and colour result markers</li>
              <li>Gross, tax, and net receipt</li>
            </ul>
            <a className="arrow-link" href="/games/blackred">
              See how BlackRed works
              <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 12h14m-5-5 5 5-5 5" /></svg>
            </a>
          </div>
          <div className="blackred-visual t-tilt" role="img" aria-label="BlackRed selection showing two labelled choices: B Black and R Red">
            <div className="game-tilt-card t-tilt-card">
              <div className="prediction prediction-black"><span>B</span><small>BLACK</small></div>
              <div className="prediction prediction-red"><span>R</span><small>RED</small></div>
              <div className="prediction-line" aria-hidden="true" />
              <div className="t-tilt-glare" aria-hidden="true" />
            </div>
          </div>
        </article>

        <article className="game-band game-band-heritage">
          <div className="game-copy">
            <p className="game-index">02 / Culture-themed instant win</p>
            <h3>Heritage</h3>
            <p>Choose five tiles from a nine-tile board. Reveal royal regalia, learn its story, and see the full result when the round settles.</p>
            <ul className="plain-list" aria-label="Heritage features">
              <li>Culturally reviewed Nigerian regalia</li>
              <li>Complete nine-tile result</li>
              <li>Verifiable second-chance receipts</li>
            </ul>
            <a className="arrow-link" href="/games/heritage">
              Discover Heritage
              <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 12h14m-5-5 5 5-5 5" /></svg>
            </a>
          </div>
          <div className="heritage-visual t-tilt" role="img" aria-label="Heritage nine-tile board with five selected numbered tiles">
            <div className="game-tilt-card t-tilt-card">
              <div className="heritage-crown" aria-hidden="true">
                <svg viewBox="0 0 180 90"><path d="M18 72 8 18l42 30L90 8l40 40 42-30-10 54Z" /><path d="M20 72h140v12H20z" /></svg>
              </div>
              <div className="tile-grid" aria-hidden="true">
                <span className="selected">07</span><span>14</span><span className="selected">23</span>
                <span>31</span><span className="selected">44</span><span>52</span>
                <span className="selected">60</span><span>71</span><span className="selected">88</span>
              </div>
              <p aria-hidden="true"><strong>5 selected</strong><span>Every tile is revealed at the end</span></p>
              <div className="t-tilt-glare" aria-hidden="true" />
            </div>
          </div>
        </article>
      </div>
    </section>
  );
}

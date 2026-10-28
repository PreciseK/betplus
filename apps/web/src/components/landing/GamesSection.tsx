import Image from "next/image";

const GAMES = [
  { id: "blackred", name: "BlackRed", type: "Card prediction", description: "Predict the colours of one to five cards. Review the possible return before confirming your stake.", image: "/assets/blackred-arena-cards.png", alt: "Black and red playing cards" },
  { id: "heritage", name: "Heritage", type: "Instant win", description: "Choose five positions on a nine-tile board and reveal matching Nigerian royal regalia.", image: "/games/heritage/monarchs/yoruba_king.png", alt: "A Yoruba king in royal attire" },
  { id: "caged", name: "Caged", type: "Live crash", description: "Watch the multiplier rise and cash out before the cage drops. Place up to two bets per round.", image: "/assets/caged-mark.svg", alt: "The golden Caged birdcage" },
];

export function GamesSection() {
  return (
    <section className="games-section" id="games" aria-labelledby="games-title">
      <div className="section-heading">
        <p className="eyebrow">The games</p>
        <h2 id="games-title">Find your game.</h2>
        <p>Each game has its own rules. Check them before you play.</p>
      </div>
      <div className="game-choice-grid">
        {GAMES.map((game, index) => (
          <article className={`game-feature game-feature-${game.id}`} key={game.id} id={game.id}>
            <a href={`/games/${game.id}`} className="game-art" aria-label={`Open ${game.name}`}>
              <Image src={game.image} alt={game.alt} width={600} height={400} unoptimized />
            </a>
            <div className="game-heading"><h3>{game.name}</h3><span aria-hidden="true">0{index + 1}</span></div>
            <p className="game-type">{game.type}</p>
            <p className="game-description">{game.description}</p>
            <a className="arrow-link" href={`/games/${game.id}`}>Play {game.name} <span aria-hidden="true">↗</span></a>
          </article>
        ))}
      </div>
    </section>
  );
}

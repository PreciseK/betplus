import React, { useState } from "react";
import styles from "./CommunitySidebarWing.module.css";
import type { HeritageTradition } from "@/mocks/heritage";
import {
  TRIBE_ATTIRE_LORE,
  RECENT_ACTIVITY_MOCK,
  HALL_OF_CHAMPIONS,
  type RecentActivityItem,
  type ChampionItem,
} from "./heritageCommunityData";

interface CommunitySidebarWingProps {
  selectedTradition?: HeritageTradition;
  recentActivity?: readonly RecentActivityItem[];
  winnersList?: readonly ChampionItem[];
}

export const CommunitySidebarWing: React.FC<CommunitySidebarWingProps> = ({
  selectedTradition,
  recentActivity = RECENT_ACTIVITY_MOCK,
  winnersList = HALL_OF_CHAMPIONS,
}) => {
  const tribeId = selectedTradition?.id ?? "yoruba";
  const lore = TRIBE_ATTIRE_LORE[tribeId] || TRIBE_ATTIRE_LORE.yoruba;
  const [activeSlotTab, setActiveSlotTab] = useState<number>(0);

  return (
    <aside className={styles.communityHub} aria-label="Heritage Community and Attire Lore">
      {/* ROW 1: RECENT ACTIVITY */}
      <section className={styles.hubCard} aria-labelledby="recent-activity-title">
        <div className={styles.cardHeader}>
          <span className={styles.cardIcon} aria-hidden="true">⚡</span>
          <h3 id="recent-activity-title">Recent Activity</h3>
          <span className={styles.liveDot} aria-label="Live updates">Live</span>
        </div>
        <ul className={styles.activityList}>
          {recentActivity.slice(0, 3).map((item) => (
            <li key={item.id} className={styles.activityItem}>
              <div className={styles.activityMeta}>
                <span className={styles.playerTag}>{item.player} ({item.tribe})</span>
                <span className={styles.timeTag}>{item.timeAgo}</span>
              </div>
              <div className={styles.activityDetail}>
                <span className={styles.actionText}>{item.action}</span>
                {item.amount && (
                  <strong className={styles.amountTag} data-badge={item.badge}>
                    {item.amount}
                  </strong>
                )}
              </div>
            </li>
          ))}
        </ul>
      </section>

      {/* ROW 2: WINNERS AREA / HALL OF CHAMPIONS */}
      <section className={styles.hubCard} aria-labelledby="winners-area-title">
        <div className={styles.cardHeader}>
          <span className={styles.cardIcon} aria-hidden="true">🏆</span>
          <h3 id="winners-area-title">Winners Area</h3>
          <span className={styles.trophyBadge}>25x Max</span>
        </div>
        <ul className={styles.winnersList}>
          {winnersList.slice(0, 3).map((champ) => (
            <li key={champ.id} className={styles.winnerItem}>
              <div className={styles.rankBadge} data-rank={champ.rank}>
                {champ.rank === 1 ? "🥇" : champ.rank === 2 ? "🥈" : "🥉"}
              </div>
              <div className={styles.winnerInfo}>
                <span className={styles.winnerName}>{champ.player}</span>
                <span className={styles.winnerTribe}>{champ.tribe} · {champ.date}</span>
              </div>
              <div className={styles.winnerPrize}>
                <strong>{champ.prize}</strong>
                <span>{champ.multiplier}</span>
              </div>
            </li>
          ))}
        </ul>
      </section>

      {/* ROW 3: TRIBE / ATTIRE INFORMATION DECK */}
      <section className={styles.hubCard} aria-labelledby="attire-lore-title">
        <div className={styles.cardHeader}>
          <span className={styles.cardIcon} aria-hidden="true">📜</span>
          <h3 id="attire-lore-title">Attire Information Deck</h3>
        </div>
        <div className={styles.attireLoreDeck}>
          <div className={styles.loreTribeHeader}>
            <strong>{lore.tribeName} Regalia</strong>
            <span>{lore.regaliaName}</span>
          </div>
          <p className={styles.loreDescription}>{lore.description}</p>

          {/* Mini Slot Selector */}
          <div className={styles.slotTabRow} role="tablist" aria-label="Attire pieces">
            {lore.pieces.map((piece, idx) => (
              <button
                key={piece.slot}
                type="button"
                role="tab"
                aria-selected={activeSlotTab === idx}
                className={styles.slotTabBtn}
                data-active={activeSlotTab === idx}
                onClick={() => setActiveSlotTab(idx)}
              >
                {piece.slot === "head" ? "👑" : piece.slot === "neck" ? "📿" : piece.slot === "torso" ? "🛡️" : piece.slot === "hand" ? "🗡️" : "👢"}
              </button>
            ))}
          </div>

          {/* Active Piece Lore Detail */}
          {lore.pieces[activeSlotTab] && (
            <div className={styles.activePieceDetail} role="tabpanel">
              <div className={styles.pieceTitleRow}>
                <strong>{lore.pieces[activeSlotTab].name}</strong>
                <span className={styles.localNameBadge}>{lore.pieces[activeSlotTab].localName}</span>
              </div>
              <p className={styles.pieceSignificance}>{lore.pieces[activeSlotTab].significance}</p>
            </div>
          )}
        </div>
      </section>
    </aside>
  );
};

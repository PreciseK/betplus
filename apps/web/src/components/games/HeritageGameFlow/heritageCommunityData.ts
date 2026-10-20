export interface TribeAttireLore {
  tribeId: string;
  tribeName: string;
  leaderTitleKing: string;
  leaderTitleQueen: string;
  regaliaName: string;
  description: string;
  pieces: {
    slot: "head" | "neck" | "torso" | "hand" | "feet";
    name: string;
    localName: string;
    significance: string;
  }[];
}

export const TRIBE_ATTIRE_LORE: Record<string, TribeAttireLore> = {
  yoruba: {
    tribeId: "yoruba",
    tribeName: "Yoruba",
    leaderTitleKing: "Ọba",
    leaderTitleQueen: "Olórì",
    regaliaName: "Aṣọ Ọba & Adé Ilẹ̀kẹ̀",
    description: "Yoruba royal regalia centers on intricately beaded crowns (Adé) symbolizing divine ancestry from Oduduwa, paired with handwoven Aṣọ-Òkè textiles.",
    pieces: [
      { slot: "head", name: "Beaded Crown", localName: "Adé Ilẹ̀kẹ̀", significance: "Sacred beaded crown with veil shielding the monarch's gaze." },
      { slot: "neck", name: "Royal Coral Beads", localName: "Ẹ̀yìn Ọrùn Ilẹ̀kẹ̀", significance: "Heavy cylindrical coral neckwear denoting supreme authority." },
      { slot: "torso", name: "Woven Grand Robe", localName: "Agbádá Aṣọ-Òkè", significance: "Luxurious embroidered silk-cotton robe woven on traditional looms." },
      { slot: "hand", name: "Beaded Flywhisk / Scepter", localName: "Irukẹrẹ", significance: "Symbol of peace, blessings, and royal judicial authority." },
      { slot: "feet", name: "Embroidered Royal Slippers", localName: "Bàtà Ilẹ̀kẹ̀", significance: "Beaded slippers preventing the monarch's feet from touching bare ground." },
    ],
  },
  igbo: {
    tribeId: "igbo",
    tribeName: "Igbo",
    leaderTitleKing: "Eze",
    leaderTitleQueen: "Lolo",
    regaliaName: "Ọkpu Eze & Akwete",
    description: "Igbo royal attire reflects honor, wisdom, and community covenant, celebrated through red caps, eagle feathers, and woven Akwete cloths.",
    pieces: [
      { slot: "head", name: "Red Cap with Eagle Feather", localName: "Ọkpu Eze na Ugo", significance: "Red felt cap adorned with white eagle feather denoting titled nobility." },
      { slot: "neck", name: "Royal Coral Neckwear", localName: "Iva Iléke", significance: "Polished red coral stones symbolizing resilience and high standing." },
      { slot: "torso", name: "Lion-Head Silk Tunic", localName: "Isiagu", significance: "Rich velvet tunic with embossed lion heads representing strength." },
      { slot: "hand", name: "Tusk / Leather Fan", localName: "Akupe & Odu", significance: "Elephant tusk horn and ceremonial fan for royal declaration." },
      { slot: "feet", name: "Royal Beaded Sandals", localName: "Akpukpo Ukwu Eze", significance: "Leather and beadwork crafted specifically for palace ceremonies." },
    ],
  },
  "hausa-fulani": {
    tribeId: "hausa-fulani",
    tribeName: "Hausa–Fulani",
    leaderTitleKing: "Sarki",
    leaderTitleQueen: "Sarauniya",
    regaliaName: "Rawani & Alkyabba",
    description: "Hausa-Fulani royal dress features flowing robes, structured turbans (Rawani) with rabbit-ear folds, and golden ceremonial cloaks.",
    pieces: [
      { slot: "head", name: "Ceremonial Turban", localName: "Rawani", significance: "Regal turban wrapped with distinctive ear-folds signifying lineage." },
      { slot: "neck", name: "Embroidered Collar", localName: "Wuyan Riga", significance: "Intricate metallic threading along the tunic neckline." },
      { slot: "torso", name: "Grand Embroidered Cloak", localName: "Alkyabba & Babbar Riga", significance: "Floor-length embroidered velvet cloak worn by emirs." },
      { slot: "hand", name: "Ceremonial Staff of Office", localName: "Gidan Sarki Staff", significance: "Gilded staff bestowed upon accession to the throne." },
      { slot: "feet", name: "Ostrich Feather Slippers", localName: "Kufai", significance: "Soft leather slippers accented with royal ostrich feathers." },
    ],
  },
  edo: {
    tribeId: "edo",
    tribeName: "Edo (Benin)",
    leaderTitleKing: "Ọba",
    leaderTitleQueen: "Iyọba",
    regaliaName: "Erhu Ivie & Ewu-Ivie",
    description: "The Benin Kingdom boasts the world-renowned coral bead mesh dress (Ewu-Ivie), crown (Erhu Ivie), and bronze ceremonial emblems.",
    pieces: [
      { slot: "head", name: "Coral Crown & Headdress", localName: "Erhu Ivie (king) · Ukpe-okhue (queen)", significance: "The king's Erhu Ivie coral crown (also called Ede) and the Iyoba's Ukpe-okhue coral headdress (also called Okuku) are each layered coral beadwork." },
      { slot: "neck", name: "Coral Collar", localName: "Odigba (king) · Ivie (queen)", significance: "The king's Odigba coral neck collar is worn alongside separate Ivie-Obo wrist beads; the queen's coral beads are called simply Ivie." },
      { slot: "torso", name: "Coral-Mesh Regalia", localName: "Ewu Ivie (king) · Ukpon-Ododo (queen)", significance: "The king's Ewu Ivie coral-beaded tunic is worn over an Ukpon base robe; the queen's coral-patterned ceremonial robe, Ukpon-Ododo, is also called simply Ukpon." },
      { slot: "hand", name: "Staff & Fan", localName: "Ukhurhe (king) · Ezuzu (queen)", significance: "The king carries the Ukhurhe ceremonial staff; the queen carries the Ezuzu ceremonial fan." },
      { slot: "feet", name: "Coral-Beaded Slippers", localName: "Ibata Ivie", significance: "Coral-beaded slippers worn by both the king and queen." },
    ],
  },
  "efik-ibibio": {
    tribeId: "efik-ibibio",
    tribeName: "Efik–Ibibio",
    leaderTitleKing: "Obong",
    leaderTitleQueen: "Ọbọñ an Iban",
    regaliaName: "Ntinya & Onyonyo",
    description: "Efik-Ibibio royalty celebrates the feathered Ntinya crown, layered flowing Onyonyo gowns, and Ekpe society sacred emblems.",
    pieces: [
      { slot: "head", name: "Royal Cap & Headpiece", localName: "Itam (king) · Eba (queen)", significance: "The king's Itam cap (also called the Ntinya-crowned cap) is worn with an Ntang Ntrukpm eagle feather; the queen's Eba hairstyle (also called Ibuot Mbopo) is adorned with Edet Idat gold/brass hair combs." },
      { slot: "neck", name: "Coral Neck Beads", localName: "Nkwa", significance: "Also called Nkwa Eto; coral neck beads worn by both the king and queen." },
      { slot: "torso", name: "Ceremonial Attire", localName: "Eku and Usobo (king) · Onyonyo (queen)", significance: "The king wears a velvet Eku top with the heavy Usobo wrapper (also called Okpomkpom); the queen's flowing Onyonyo gown is its own garment." },
      { slot: "hand", name: "Staff & Fan", localName: "Esang Ubong (king) · Ufat (queen)", significance: "The king carries the Esang Ubong royal staff of office; the queen carries the Ufat ceremonial hand fan." },
      { slot: "feet", name: "Court Shoes", localName: "Ikpa Ukot", significance: "Ceremonial court shoes worn by both the king and queen." },
    ],
  },
  ijaw: {
    tribeId: "ijaw",
    tribeName: "Ijaw",
    leaderTitleKing: "Amanyanabo",
    leaderTitleQueen: "Queen Consort",
    regaliaName: "Etibo & Coral Regalia",
    description: "Ijaw coastal kings don bespoke tailored Etibo jumpers, bowler-style Ogbogbo hats, and magnificent Ila coral strings.",
    pieces: [
      { slot: "head", name: "Royal Hat & Headtie", localName: "Ogbogbo (king) · Egenekpe (queen)", significance: "The king's Ogbogbo hat (also called Bolou, a bowler-style hat) is worn with an Agbalakoko eagle feather; the queen's Egenekpe George headtie is also called Tukpa or Tibibedegi." },
      { slot: "neck", name: "Coral Neck Beads", localName: "Ila", significance: "Also called Pina-ila; coral neck beads worn by both the king and queen." },
      { slot: "torso", name: "Jumper & George Wrapper", localName: "Etibo and Injiri (king) · Ewu and Injiri (queen)", significance: "The king's Etibo royal jumper (also called Don) is worn with the heavy Injiri George wrapper (also called Ikagi); the queen's Ewu embroidered blouse (also called Keme-sibi) is worn with a double-tied Injiri wrapper." },
      { slot: "hand", name: "Staff & Fan", localName: "Okubo (king) · Azuzu (queen)", significance: "The king carries the Okubo walking staff of office (also called Akparakpa) and a separate Azuzu horsehair fly-whisk; the queen carries an Azuzu beaded hand fan (also called Efefe)." },
      { slot: "feet", name: "Ceremonial Shoes", localName: "Angala-gbasa", significance: "Also called Bue-ere; ceremonial shoes worn by both the king and queen." },
    ],
  },
  "middle-belt": {
    tribeId: "middle-belt",
    tribeName: "Middle Belt",
    leaderTitleKing: "Etsu / Shehu / Tor",
    leaderTitleQueen: "Queen Mother",
    regaliaName: "A'nger & Gbough",
    description: "Diverse royal traditions across Tiv, Nupe, and Jukun featuring bold black-and-white A'nger textiles and carved royal staffs.",
    pieces: [
      { slot: "head", name: "Woven Cap & Headtie", localName: "Agbande-Tsa (king) · Ichoho-Tsa (queen)", significance: "The king's Agbande-Tsa A'nger-woven cap (also called Adzenga) and the queen's Ichoho-Tsa A'nger headtie." },
      { slot: "neck", name: "Coral & Traditional Beads", localName: "Akaa", significance: "Also called Mbakaa; royal coral/traditional beads worn by both the king and queen." },
      { slot: "torso", name: "A'nger Ensemble", localName: "A'nger over Ivavtyo (king) · A'nger with Riga (queen)", significance: "The iconic black-and-white striped Tiv A'nger wrapper: worn over an Ivavtyo base tunic by the king, and paired with a Riga (or Ivavtyo) lace blouse by the queen." },
      { slot: "hand", name: "Staff & Fan", localName: "Gbough (king) · Pepe (queen)", significance: "The king carries the Gbough carved royal staff of office; the queen carries the Pepe carved wooden hand fan." },
      { slot: "feet", name: "A'nger-Upholstered Shoes", localName: "Akov a Anger", significance: "A'nger-upholstered royal shoes worn by both the king and queen." },
    ],
  },
};

export interface RecentActivityItem {
  id: string;
  timeAgo: string;
  player: string;
  tribe: string;
  action: string;
  amount?: string;
  badge: "win" | "match" | "second-chance";
}

export const RECENT_ACTIVITY_MOCK: RecentActivityItem[] = [
  { id: "act-1", timeAgo: "1m ago", player: "Ade***", tribe: "Yoruba", action: "Matched 5/5 Jackpot!", amount: "+₦100,000", badge: "win" },
  { id: "act-2", timeAgo: "3m ago", player: "Chidi***", tribe: "Igbo", action: "Equipped 4/5 Royal items", amount: "+₦5,000", badge: "match" },
  { id: "act-3", timeAgo: "6m ago", player: "Fatima***", tribe: "Hausa–Fulani", action: "Won 5/90 Second-Chance Draw", amount: "Draw Ticket", badge: "second-chance" },
  { id: "act-4", timeAgo: "9m ago", player: "Efe***", tribe: "Edo (Benin)", action: "Matched 4/5 pieces", amount: "+₦2,500", badge: "match" },
  { id: "act-5", timeAgo: "12m ago", player: "Bassey***", tribe: "Efik–Ibibio", action: "Revealed Sacred Crown (Ntinya)", amount: "+₦500", badge: "match" },
];

export interface ChampionItem {
  id: string;
  rank: number;
  player: string;
  tribe: string;
  prize: string;
  multiplier: string;
  date: string;
}

export const HALL_OF_CHAMPIONS: ChampionItem[] = [
  { id: "champ-1", rank: 1, player: "Tunde***92", tribe: "Yoruba", prize: "₦500,000", multiplier: "25x", date: "Today" },
  { id: "champ-2", rank: 2, player: "Ngozi***44", tribe: "Igbo", prize: "₦250,000", multiplier: "25x", date: "Yesterday" },
  { id: "champ-3", rank: 3, player: "Ibrahim***01", tribe: "Hausa–Fulani", prize: "₦125,000", multiplier: "25x", date: "2 days ago" },
  { id: "champ-4", rank: 4, player: "Osaze***17", tribe: "Edo (Benin)", prize: "₦100,000", multiplier: "25x", date: "3 days ago" },
];

export const MONARCH_ARTWORK_MAP: Record<string, { king?: string; queen?: string }> = {
  yoruba: {
    king: "/games/heritage/monarchs/yoruba_king_v2.png",
    queen: "/games/heritage/monarchs/yoruba_queen_v2.png",
  },
  igbo: {
    king: "/games/heritage/monarchs/igbo_king_v2.png",
    queen: "/games/heritage/monarchs/igbo_queen_v2.png",
  },
  "hausa-fulani": {
    king: "/games/heritage/monarchs/hausa-fulani_king_v2.png",
    queen: "/games/heritage/monarchs/hausa-fulani_queen_v2.png",
  },
  edo: {
    king: "/games/heritage/monarchs/edo_king_v2.png",
    queen: "/games/heritage/monarchs/edo_queen_v2.png",
  },
  "edo-benin": {
    king: "/games/heritage/monarchs/edo_king_v2.png",
    queen: "/games/heritage/monarchs/edo_queen_v2.png",
  },
  "efik-ibibio": {
    king: "/games/heritage/monarchs/efik-ibibio_king_v2.png",
    queen: "/games/heritage/monarchs/efik-ibibio_queen_v2.png",
  },
  ijaw: {
    king: "/games/heritage/monarchs/ijaw_king_v2.png",
    queen: "/games/heritage/monarchs/ijaw_queen_v2.png",
  },
  "middle-belt": {
    king: "/games/heritage/monarchs/middle-belt_king_v2.png",
    queen: "/games/heritage/monarchs/middle-belt_queen_v2.png",
  },
};


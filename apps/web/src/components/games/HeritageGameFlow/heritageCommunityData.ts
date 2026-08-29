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
      { slot: "head", name: "High Coral Crown", localName: "Erhu Ivie", significance: "Dome-shaped solid coral mesh crown handed down across dynasties." },
      { slot: "neck", name: "Layered Coral Choker", localName: "Odigba", significance: "Multi-tiered coral choker encasing the neck in royal red." },
      { slot: "torso", name: "Full Coral Mesh Robe", localName: "Ewu-Ivie", significance: "Thousands of sacred coral beads woven into a majestic tunic." },
      { slot: "hand", name: "Bronze Scepter / Ceremonial Sword", localName: "Ada & Eben", significance: "Traditional bronze blades danced during royal palace rites." },
      { slot: "feet", name: "Palace Beaded Slippers", localName: "Bata Ivie", significance: "Ornate coral-studded footwear exclusive to the royal court." },
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
      { slot: "head", name: "Sacred Feathered Crown", localName: "Ntinya", significance: "Conical crown woven with rare bird feathers symbolizing high chieftaincy." },
      { slot: "neck", name: "Court Beaded Collar", localName: "Mkpat", significance: "Cascading beaded collar draped over the royal shoulders." },
      { slot: "torso", name: "Flowing Silk Brocade", localName: "Onyonyo / Ikpanya", significance: "Voluminous high-society dress with ornate metallic embroidery." },
      { slot: "hand", name: "Staff of Peace & Whisk", localName: "Esan Obong", significance: "Staff carved with Ekpe ancestral motifs for ceremonial blessings." },
      { slot: "feet", name: "Velvet Court Shoes", localName: "Ikpa Ukot", significance: "Velvet slippers with brass embellishments." },
    ],
  },
  ijaw: {
    tribeId: "ijaw",
    tribeName: "Ijaw",
    leaderTitleKing: "Amanyanabo",
    leaderTitleQueen: "Queen Consort",
    regaliaName: "Woko & Coral Regalia",
    description: "Ijaw coastal kings don bespoke tailored Woko jackets, bowler hats with gold bands, and magnificent coral strings.",
    pieces: [
      { slot: "head", name: "Feathered Crown Hat", localName: "Sun-hat with Gold Trim", significance: "High-brim crown hat adorned with aquatic motifs." },
      { slot: "neck", name: "Heavy River Coral", localName: "Ibu Iléke", significance: "Chunky barrel corals traded along the historic delta coast." },
      { slot: "torso", name: "Royal Woko Tunic", localName: "Woko & Wrapper", significance: "Structured high-collar tunic worn over silk George wrappers." },
      { slot: "hand", name: "Ivory Head Walking Staff", localName: "Kpoko Staff", significance: "Carved ivory cane symbolizing steady leadership over waterways." },
      { slot: "feet", name: "Polished Palace Shoes", localName: "Royal Court Shoes", significance: "Handmade leather loafers with gold buckles." },
    ],
  },
  "middle-belt": {
    tribeId: "middle-belt",
    tribeName: "Middle Belt",
    leaderTitleKing: "Etsu / Shehu / Tor",
    leaderTitleQueen: "Queen Mother",
    regaliaName: "A'nger & Babban Riga",
    description: "Diverse royal traditions across Tiv, Nupe, and Jukun featuring bold black-and-white A'nger textiles and royal bronze regalia.",
    pieces: [
      { slot: "head", name: "Striped Royal Headdress", localName: "A'nger Cap", significance: "Black-and-white woven fabric headdress denoting balance and justice." },
      { slot: "neck", name: "Glass & Coral Strand", localName: "Bida Glass Beads", significance: "Historic Nupe brass and glasswork necklace." },
      { slot: "torso", name: "Woven Warrior Mantle", localName: "Tiv A'nger Robe", significance: "Signature geometric striped textile representing ancestral courage." },
      { slot: "hand", name: "Horsehair Whisk & Staff", localName: "Kume & Staff", significance: "Traditional horsehair wand for dispersing negative energy." },
      { slot: "feet", name: "Hide Palace Mules", localName: "Leather Slippers", significance: "Specially cured leather mules for state occasions." },
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
    king: "/games/heritage/monarchs/yoruba_king.png",
    queen: "/games/heritage/monarchs/yoruba_queen.png",
  },
  igbo: {},
  "hausa-fulani": {},
  "edo-benin": {},
  "efik-ibibio": {},
  ijaw: {},
  "middle-belt": {},
};


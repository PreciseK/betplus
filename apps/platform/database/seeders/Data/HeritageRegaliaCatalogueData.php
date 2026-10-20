<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

/**
 * The 70 real, reviewed Heritage regalia pieces (7 tribes x king/queen x 5 slots:
 * head/neck/torso/hand/feet), transcribed from
 * apps/web/src/mocks/heritageRegaliaCatalogue.ts so the frontend and the real
 * catalogue agree on names and artwork. Pure data — HeritageGameSeeder turns each
 * row into a heritageCatalogueItem.
 */
final class HeritageRegaliaCatalogueData
{
    /** @return list<array{tribe: string, label: string, leader: string, slot: string, localName: string, english: string, src: string, description: string}> */
    public static function items(): array
    {
        return [
            ...self::set('yoruba', 'Yoruba', 'king', [
                ['head', 'Adé', 'Beaded crown', 'yoruba_king/ade_v1.png', "The king's sacred beaded crown."],
                ['neck', 'Ìlẹ̀kẹ̀', 'Coral neck beads', 'yoruba_king/ileke_v1.png', "The king's coral neck beads."],
                ['torso', 'Agbádá', 'Embroidered robe', 'yoruba_king/agbada_v1.png', "The king's embroidered Aṣọ-Òkè robe."],
                ['hand', 'Ìrùkẹ̀rẹ̀', 'Flywhisk', 'yoruba_king/irukere_v1.png', "The king's ceremonial flywhisk."],
                ['feet', 'Bàtà', 'Embroidered shoes', 'yoruba_king/bata_v1.png', "The king's embroidered royal slippers."],
            ]),
            ...self::set('yoruba', 'Yoruba', 'queen', [
                ['head', 'Gèlè', 'Sculpted headwrap', 'yoruba_queen/gele_v1.png', "The queen's sculpted Aṣọ-Òkè headwrap."],
                ['neck', 'Ìlẹ̀kẹ̀', 'Coral neck beads', 'yoruba_queen/ileke_v1.png', "The queen's coral neck beads."],
                ['torso', 'Ìró àti Bùbá', 'Blouse and wrapper', 'yoruba_queen/iro-buba_v1.png', "The queen's blouse and wrapper ensemble."],
                ['hand', 'Abẹ́bẹ́', 'Hand fan', 'yoruba_queen/abebe_v1.png', "The queen's ceremonial hand fan."],
                ['feet', 'Bàtà', 'Beaded shoes', 'yoruba_queen/bata_v1.png', "The queen's beaded royal slippers."],
            ]),
            ...self::set('igbo', 'Igbo', 'king', [
                ['head', 'Okpu Eze', 'Royal cap', 'igbo_king/okpu-eze_v1.png', "The king's red royal cap."],
                ['neck', 'Ákà', 'Coral neck beads', 'igbo_king/aka_v1.png', "The king's coral neck beads."],
                ['torso', 'Isiagụ', 'Lion-patterned tunic', 'igbo_king/isiagu_v1.png', "The king's lion-patterned Isiagu tunic."],
                ['hand', 'Mkpọ', 'Carved walking staff', 'igbo_king/mkpo_v1.png', "The king's carved ceremonial staff."],
                ['feet', 'Akpụkpọ ụkwụ', 'Embroidered shoes', 'igbo_king/akpukpo-ukwu_v1.png', "The king's embroidered royal shoes."],
            ]),
            ...self::set('igbo', 'Igbo', 'queen', [
                ['head', 'Okpu ákà', 'Beaded headpiece', 'igbo_queen/okpu-aka_v1.png', "The queen's beaded headpiece."],
                ['neck', 'Ákà', 'Coral neck beads', 'igbo_queen/aka_v1.png', "The queen's coral neck beads."],
                ['torso', 'Akwa', 'Blouse and woven wrapper', 'igbo_queen/akwa_v1.png', "The queen's blouse and woven wrapper."],
                ['hand', 'Àkùpè', 'Feather hand fan', 'igbo_queen/akupe_v1.png', "The queen's feather hand fan."],
                ['feet', 'Akpụkpọ ụkwụ', 'Beaded shoes', 'igbo_queen/akpukpo-ukwu_v1.png', "The queen's beaded royal shoes."],
            ]),
            ...self::set('hausa_fulani', 'Hausa–Fulani', 'king', [
                ['head', 'Rawani', 'Ceremonial turban', 'hausa-fulani_king/rawani_v1.png', "The emir's ceremonial turban."],
                ['neck', 'Abin wuya', 'Embroidered neck panel', 'hausa-fulani_king/abin-wuya_v1.png', "The emir's embroidered neck panel."],
                ['torso', 'Babbar riga da alkyabba', 'Robe and ceremonial cloak', 'hausa-fulani_king/babbar-riga_v1.png', "The emir's robe and ceremonial cloak."],
                ['hand', 'Sanda', 'Ceremonial staff', 'hausa-fulani_king/sanda_v1.png', "The emir's ceremonial staff."],
                ['feet', 'Takalmi', 'Embroidered slippers', 'hausa-fulani_king/takalmi_v1.png', "The emir's embroidered slippers."],
            ]),
            ...self::set('hausa_fulani', 'Hausa–Fulani', 'queen', [
                ['head', 'Ɗankwali', 'Headwrap and veil', 'hausa-fulani_queen/dankwali_v1.png', "The queen's headwrap and veil."],
                ['neck', 'Abin wuya', 'Gold necklace', 'hausa-fulani_queen/abin-wuya_v1.png', "The queen's gold necklace."],
                ['torso', 'Riga', 'Embroidered ceremonial gown', 'hausa-fulani_queen/riga_v1.png', "The queen's embroidered ceremonial gown."],
                ['hand', 'Mafici', 'Hand fan', 'hausa-fulani_queen/mafici_v1.png', "The queen's ceremonial hand fan."],
                ['feet', 'Takalmi', 'Embroidered shoes', 'hausa-fulani_queen/takalmi_v1.png', "The queen's embroidered shoes."],
            ]),
            ...self::set('edo', 'Edo (Benin)', 'king', [
                ['head', 'Erhu Ivie', 'Coral crown', 'edo_king/erhu-ivie_v1.png', "Also called Ede; the king's coral crown."],
                ['neck', 'Odigba', 'Layered coral collar', 'edo_king/odigba_v1.png', "The king's coral neck collar, distinct from the Ivie-Obo coral wrist beads worn in the same portrait."],
                ['torso', 'Ewu Ivie', 'Coral-mesh ceremonial attire', 'edo_king/ewu-ivie_v1.png', "The king's coral-beaded tunic, worn over an Ukpon base robe."],
                ['hand', 'Ukhurhe', 'Bronze-toned ceremonial staff', 'edo_king/staff_v1.png', "The king's ceremonial staff."],
                ['feet', 'Ibata Ivie', 'Coral-beaded slippers', 'edo_king/slippers_v1.png', "The king's coral-beaded slippers."],
            ]),
            ...self::set('edo', 'Edo (Benin)', 'queen', [
                ['head', 'Ukpe-okhue', 'Coral-beaded headdress', 'edo_queen/ukpe-okhue_v1.png', "Also called Okuku; the Iyoba's coral headdress."],
                ['neck', 'Ivie', 'Coral collar', 'edo_queen/odigba_v1.png', "The queen's coral beads."],
                ['torso', 'Ukpon-Ododo', 'Coral and ivory ceremonial attire', 'edo_queen/ceremonial-attire_v1.png', "Also called simply Ukpon; the queen's coral-patterned ceremonial robe."],
                ['hand', 'Ezuzu', 'Feather hand fan', 'edo_queen/fan_v1.png', "The queen's ceremonial fan."],
                ['feet', 'Ibata Ivie', 'Beaded shoes', 'edo_queen/slippers_v1.png', "The queen's coral-beaded slippers."],
            ]),
            ...self::set('efik_ibibio', 'Efik–Ibibio', 'king', [
                ['head', 'Itam', 'Embroidered feathered cap', 'efik-ibibio_king/cap_v1.png', "Also called the Ntinya-crowned cap, worn with an Ntang Ntrukpm eagle feather."],
                ['neck', 'Nkwa', 'Coral neck beads', 'efik-ibibio_king/neck-beads_v1.png', "Also called Nkwa Eto; the king's coral neck beads."],
                ['torso', 'Eku and Usobo', 'Tunic and ceremonial wrapper', 'efik-ibibio_king/ceremonial-attire_v1.png', "The velvet Eku top worn with the heavy George wrapper, also called Okpomkpom."],
                ['hand', 'Esang Ubong', 'Gold-topped staff', 'efik-ibibio_king/staff_v1.png', "The king's royal staff of office."],
                ['feet', 'Ikpa Ukot', 'Embroidered court shoes', 'efik-ibibio_king/shoes_v1.png', "The king's ceremonial court shoes."],
            ]),
            ...self::set('efik_ibibio', 'Efik–Ibibio', 'queen', [
                ['head', 'Eba', 'Coral and gold hair ornaments', 'efik-ibibio_queen/headpiece_v1.png', "Also called Ibuot Mbopo; the queen's hairstyle, adorned with Edet Idat gold/brass hair combs."],
                ['neck', 'Nkwa', 'Coral neck beads', 'efik-ibibio_queen/neck-beads_v1.png', "Also called Nkwa Eto; the queen's coral neck beads."],
                ['torso', 'Onyonyo', 'Ceremonial gown', 'efik-ibibio_queen/onyonyo_v1.png', "The queen's flowing ceremonial gown."],
                ['hand', 'Ufat', 'Feather hand fan', 'efik-ibibio_queen/fan_v1.png', "The queen's ceremonial hand fan."],
                ['feet', 'Ikpa Ukot', 'Decorated court shoes', 'efik-ibibio_queen/shoes_v1.png', "The queen's ceremonial court shoes."],
            ]),
            ...self::set('ijaw', 'Ijaw', 'king', [
                ['head', 'Ogbogbo', 'Coral-trimmed royal hat', 'ijaw_king/hat_v1.png', "Also called Bolou (bowler hat), worn with an Agbalakoko eagle feather."],
                ['neck', 'Ila', 'Coral neck beads', 'ijaw_king/neck-beads_v1.png', "Also called Pina-ila; the king's coral neck beads."],
                ['torso', 'Etibo and Injiri', 'Embroidered shirt and wrapper', 'ijaw_king/etibo-george_v1.png', "Also called Don (jumper) and Ikagi (wrapper); the king's royal jumper and heavy George wrapper."],
                ['hand', 'Okubo', 'Carved walking staff', 'ijaw_king/staff_v1.png', "Also called Akparakpa; the king's walking staff of office. A separate Azuzu horsehair fly-whisk is also part of Ijaw royal regalia but isn't depicted as its own board piece."],
                ['feet', 'Angala-gbasa', 'Embroidered palace shoes', 'ijaw_king/shoes_v1.png', "Also called Bue-ere; the king's ceremonial shoes."],
            ]),
            ...self::set('ijaw', 'Ijaw', 'queen', [
                ['head', 'Egenekpe', 'Sculpted headtie', 'ijaw_queen/headtie_v1.png', "Also called Tukpa or Tibibedegi; the queen's George headtie."],
                ['neck', 'Ila', 'Coral neck beads', 'ijaw_queen/neck-beads_v1.png', "Also called Pina-ila; the queen's coral neck beads."],
                ['torso', 'Ewu and Injiri', 'Blouse and ceremonial wrapper', 'ijaw_queen/george-attire_v1.png', "Also called Keme-sibi (blouse) and Ikagi (wrapper); the queen's embroidered blouse and double-tied George wrapper."],
                ['hand', 'Azuzu', 'Beaded hand fan', 'ijaw_queen/fan_v1.png', "Also called Efefe; the queen's beaded hand fan."],
                ['feet', 'Angala-gbasa', 'Beaded shoes', 'ijaw_queen/shoes_v1.png', "Also called Bue-ere; the queen's ceremonial shoes."],
            ]),
            ...self::set('middle_belt', 'Middle Belt', 'king', [
                ['head', 'Agbande-Tsa', 'Striped woven cap', 'middle-belt_king/anger-cap_v1.png', "Also called Adzenga; the king's A'nger-woven cap."],
                ['neck', 'Akaa', 'Coral and brass neck ornament', 'middle-belt_king/neck-beads_v1.png', "Also called Mbakaa; the king's royal coral/traditional beads."],
                ['torso', "A'nger (over Ivavtyo)", 'Woven mantle and tunic', 'middle-belt_king/anger-attire_v1.png', "The iconic black-and-white striped Tiv wrapper, worn over an Ivavtyo base tunic."],
                ['hand', 'Gbough', 'Carved wooden staff', 'middle-belt_king/staff_v1.png', "The king's carved royal staff of office."],
                ['feet', 'Akov a Anger', 'Striped leather slippers', 'middle-belt_king/slippers_v1.png', "The king's A'nger-upholstered royal shoes."],
            ]),
            ...self::set('middle_belt', 'Middle Belt', 'queen', [
                ['head', 'Ichoho-Tsa', 'Striped woven headwrap', 'middle-belt_queen/anger-headwrap_v1.png', "Also called the A'nger headtie."],
                ['neck', 'Akaa', 'Coral neck beads', 'middle-belt_queen/neck-beads_v1.png', "Also called Mbakaa; the queen's royal coral/traditional beads."],
                ['torso', "A'nger (with Riga)", 'Blouse and woven wrapper', 'middle-belt_queen/anger-attire_v1.png', "The black-and-white striped Tiv wrapper and shoulder drape, paired with a Riga (or Ivavtyo) lace blouse."],
                ['hand', 'Pepe', 'Carved wooden hand fan', 'middle-belt_queen/fan_v1.png', "The queen's carved wooden hand fan."],
                ['feet', 'Akov a Anger', 'Beaded leather shoes', 'middle-belt_queen/slippers_v1.png', "The queen's A'nger-upholstered slippers."],
            ]),
        ];
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $pieces [slot, localName, english, srcSuffix, description]
     * @return list<array{tribe: string, label: string, leader: string, slot: string, localName: string, english: string, src: string, description: string}>
     */
    private static function set(string $tribe, string $label, string $leader, array $pieces): array
    {
        return array_map(
            fn (array $piece) => [
                'tribe' => $tribe,
                'label' => $label,
                'leader' => $leader,
                'slot' => $piece[0],
                'localName' => $piece[1],
                'english' => $piece[2],
                'src' => '/games/heritage/regalia/' . $piece[3],
                'description' => $piece[4],
            ],
            $pieces,
        );
    }
}

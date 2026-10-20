# Heritage regalia artwork

The current illustrations are fictional concept artwork for UI review. They are not approved historical or ceremonial depictions. The inspection dialog identifies them as concept artwork pending regalia review.

Generated with the built-in image generator in October 2026. The PNG was copied unchanged into `apps/web/public/games/heritage/regalia/concept-atlas-v1.png`. CSS samples the nine equal cells; no raster post-processing was applied.

## Replacement artwork

The catalogue client accepts an optional `image_url` per item and maps it to `imageUrl`. When present, the board, equipment slot and inspection dialog use that individual image instead of the concept atlas. The service can supply approved assets through that field when the regalia review is complete. This change does not add a database migration or publication approval workflow.

Without an individual image, featured numbers use the cells below; other catalogue items use a generic illustration for their equipment slot. A fallback illustration is not evidence of an item's authenticity.

| Cell (row, column) | Featured number | Concept             |
| ------------------ | --------------- | ------------------- |
| 1, 1               | 7               | Blue and gold crown |
| 1, 2               | 14              | Coral collar        |
| 1, 3               | 23              | Indigo wrapper      |
| 2, 1               | 31              | Teal turban         |
| 2, 2               | 42              | Emerald necklace    |
| 2, 3               | 55              | Wooden staff        |
| 3, 1               | 63              | Purple robe         |
| 3, 2               | 74              | Beaded bracelets    |
| 3, 3               | 88              | Burgundy slippers   |

## Generation prompt

Create ONE square transparent PNG sprite atlas for a premium African-inspired royal RPG inventory UI. A perfectly regular 3 by 3 grid of NINE separate item illustrations, each fully contained within its own equal square cell, centered precisely, with generous transparent margins. No grid lines, no text, no labels, no numbers, no UI frames, no backdrop, no cast shadows outside each object. High-quality hand-painted 3D game item style, polished gold, coral, indigo and emerald accents, readable silhouettes and rich tactile materials. FICTIONAL CONCEPT ART FOR DESIGN REVIEW, not authentic historical depictions: do not copy living rulers, active palace regalia, sacred objects, protected royal insignia, faces or religious marks. Row 1 left to right: a fictional ornate gold-and-blue beaded royal crown; a coral-red layered collar necklace; a folded indigo-and-gold ceremonial wrapper textile. Row 2 left to right: a richly wrapped teal ceremonial turban with a modest gold ornament; an emerald-and-gold court necklace; a carved wooden royal staff with a gold finial. Row 3 left to right: an embroidered deep-violet royal robe displayed as a garment with no mannequin or person; a matched pair of coral-and-gold beaded bracelets; a matched pair of decorated burgundy-and-gold royal slippers. Each of the nine cells has exactly one listed item or pair of items, no overlap across cells. Shared scale, consistent front three-quarter lighting, crisp detailed shapes. Transparent background everywhere between objects. The sheet will be sampled as nine equal cells directly in CSS.

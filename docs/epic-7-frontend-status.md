# Epic 7 frontend implementation status

Heritage is implemented as one full-canvas, single-page game. Configuration, ticket confirmation, sequential reveal, settlement proof, second-chance status and session facts remain in the same surface. The frontend mock is deterministic and injectable; production settlement authority remains a backend responsibility.

## Epic story coverage

| Story | Frontend status | Primary surface |
| --- | --- | --- |
| 7.1 Identical board on every channel | Implemented | Fixed nine-position board, exactly five selectable positions and Quick Pick |
| 7.2 Consistent outcome and reveal | Implemented | Ticket-first settlement contract and one-way sequential tile reveal |
| 7.3 Full board at settlement | Implemented | All nine positions, four explicit picked/winning states and neutral `1–2 match` loss copy |
| 7.4 Immutable 90-item catalogue | Implemented | Frozen one-to-one `1–90` frontend manifest with stable featured board numbers |
| 7.5 Cultural publication gate | Implemented for frontend | Sign-off metadata, preview-only status, depiction constraints and withheld placeholder copy |
| 7.6 Tradition and leader choice | Implemented | Tradition and king/queen configuration with an explicit no-odds-effect statement |
| 7.7 Second-chance draw entry | Implemented | Pending, lodged and after-cut-off rollover presentations with numbers, draw time and references |
| 7.8 Partner failure | Implemented | Delayed and compensated states; play and primary settlement remain available |
| 7.9 Result notification | Implemented | SMS delivery state and explicit win-or-loss notification promise |
| 7.10 Accessible Heritage | Implemented | Live match count, precise reveal announcements, focusable result proof and reduced-motion instant settlement |

## Interaction and content rules

1. The board is the visual anchor; the tile flip is the only expressive motion.
2. A selection changes placement only. It never implies a re-roll or changes the predetermined tier.
3. Unpicked winning positions are labelled as factual outcome evidence, never as a near miss.
4. Losses use neutral language and make exit more prominent than starting another round.
5. Each revealed position exposes its catalogue number, name, origin, cultural context, picked state and winning state in text.
6. Partner references appear only after a successful lodge. Pending and delayed entries say that no partner reference exists.
7. Reduced-motion users receive the complete result immediately with the same settlement meaning.

## Remaining integration boundaries

- Replace the injectable Heritage gateway with the production ticket and settlement API.
- Populate only culturally approved catalogue entries and artwork from the signed content source; preview-only entries must remain unpublished.
- Connect second-chance status and SMS delivery updates to asynchronous backend events.
- Source balances, session limits and elapsed time from authenticated player services.


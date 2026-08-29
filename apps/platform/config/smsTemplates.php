<?php

declare(strict_types=1);

// Versioned SMS templates (REQ-NOT-002). :placeholders are substituted by SmsSender.
// Bump the version key (e.g. 'v2' under 'otp') rather than editing v1's text once shipped.
return [
    'otp' => [
        'v1' => 'Your Betplus verification code is :code. It expires in 5 minutes. Do not share this code.',
    ],
    // REQ-HG-036 — digital receipt within 5 minutes of confirmed lodgement.
    'heritage_second_chance' => [
        'v1' => 'Betplus: your 5/90 entry :numbers is lodged for :draw_name (:draw_time). Partner ref :partner_ref, ticket :ticket_ref.',
    ],
    // REQ-HG-038 — every second-chance player is told the result, win or not.
    'heritage_second_chance_result' => [
        'v1' => 'Betplus: your :draw_name result is in — :outcome. Ticket :ticket_ref.',
    ],
    // REQ-HG-037 — plain notice when an entry could not be lodged and was compensated.
    'heritage_second_chance_compensated' => [
        'v1' => 'Betplus: we could not lodge your 5/90 entry after 3 draws, so we have credited :amount back to your Play Balance. Ticket :ticket_ref.',
    ],
    // REQ-USSD-005 / REQ-NOT-008 — mirrors a ticket outcome to SMS so a dropped USSD
    // session never costs the player their result.
    'ticket_receipt' => [
        'v1' => 'Betplus :game result: :result. Ticket :reference.',
    ],
];

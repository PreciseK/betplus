<?php

declare(strict_types=1);

return [
    // REQ-SEC-011/REQ-HOST-003 — loopback only in every real environment; the default
    // matches apps/engine-heritage/README.md's run command exactly.
    'engine_base_url' => env('HERITAGE_ENGINE_BASE_URL', 'http://127.0.0.1:8100'),
    'engine_timeout_seconds' => (int) env('HERITAGE_ENGINE_TIMEOUT_SECONDS', 5),

    // REQ-HG-054 — enumerated in configuration, not hardcoded. PRD §9.6's launch set.
    'traditions' => [
        'yoruba' => ['label' => 'Yoruba', 'king_title' => 'Ọba', 'queen_title' => 'Olorì'],
        'igbo' => ['label' => 'Igbo', 'king_title' => 'Eze', 'queen_title' => 'Lolo'],
        'hausa_fulani' => ['label' => 'Hausa–Fulani', 'king_title' => 'Sarki', 'queen_title' => 'Sarauniya'],
        'edo' => ['label' => 'Edo (Benin)', 'king_title' => 'Ọba', 'queen_title' => 'Iyọba'],
        'efik_ibibio' => ['label' => 'Efik–Ibibio', 'king_title' => 'Obong', 'queen_title' => 'Ọbọñ an Iban'],
        'ijaw' => ['label' => 'Ijaw', 'king_title' => 'Amanyanabo', 'queen_title' => 'Amanyanabo'],
        'middle_belt' => ['label' => 'Middle Belt (Nupe, Kanuri, Tiv)', 'king_title' => 'Etsu / Shehu / Tor', 'queen_title' => 'Etsu / Shehu / Tor'],
    ],
];

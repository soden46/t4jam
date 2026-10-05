<?php

return [
    // Inferred level policy; see docs/automation-reference-parity.md.
    // The observed reference sequence is 120000 -> 172800 for results 2 -> 3.
    'scaling' => [
        'minimum_results' => 2,
        'levels_per_increase' => 2,
        'level_ratio' => 1.20,
        'first_level_budget' => 100000,
        'hold_spend_multipliers' => ['onhold' => 1, 'bypass' => 3],
    ],
];

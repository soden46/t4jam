<?php

return [
    // Each pair of results earns two levels: 77000 -> 100000 -> 120000,
    // then 144000 -> 172800 -> 207360 -> 248832; see the user's budget history.
    'scaling' => [
        'minimum_results' => 2,
        'results_per_increase' => 2,
        'levels_per_result_batch' => 2,
        'levels_per_increase' => 2,
        'level_ratio' => 1.20,
        'first_level_budget' => 100000,
        'hold_spend_multipliers' => ['onhold' => 1, 'bypass' => 3],
    ],
];

<?php

return [
    // First result pair targets 120000; each later pair earns four 20% levels.
    'scaling' => [
        'minimum_results' => 2,
        'results_per_increase' => 2,
        'initial_result_budget' => 120000,
        'levels_per_result_batch' => 4,
        'levels_per_increase' => 2,
        'level_ratio' => 1.20,
        'first_level_budget' => 100000,
        'hold_spend_multipliers' => ['onhold' => 1, 'bypass' => 3],
    ],
];

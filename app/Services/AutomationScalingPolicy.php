<?php

namespace App\Services;

use App\Models\AutomationTask;

class AutomationScalingPolicy
{
    public function nextAutomaticBudget(int $budget, int $batches, int $maximum = 0): int
    {
        for ($batch = 0; $batch < $batches; $batch++) {
            $before = $budget;
            $budget = $this->increaseByLevels($budget, (int) config('automation.scaling.levels_per_result_batch', 2), $maximum);
            if ($budget <= $before || ($maximum > 0 && $budget >= $maximum)) {
                break;
            }
        }

        return $budget;
    }

    public function nextBudget(int $budget, int $maximum = 0): int
    {
        return $this->increaseByLevels($budget, (int) config('automation.scaling.levels_per_increase', 2), $maximum);
    }

    private function increaseByLevels(int $budget, int $levels, int $maximum): int
    {
        $next = $budget;
        for ($i = 0; $i < $levels; $i++) {
            $next = (int) min(2000000000, max(
                (int) config('automation.scaling.first_level_budget', 100000),
                round($next * (float) config('automation.scaling.level_ratio', 1.2)),
            ));
        }

        return $maximum > 0 ? min($next, $maximum) : $next;
    }

    public function previousBudget(int $budget, int $startingBudget): int
    {
        for ($i = 0; $i < (int) config('automation.scaling.levels_per_increase', 2); $i++) {
            $budget = (int) floor($budget / (float) config('automation.scaling.level_ratio', 1.2));
        }

        return max($startingBudget, $budget, 1000);
    }

    public function spendAllowsIncrease(AutomationTask $task, int $budget, int $spend): bool
    {
        if ($task->system_flow === 'loss') {
            return true;
        }

        $multiplier = config('automation.scaling.hold_spend_multipliers.'.$task->system_flow);

        return is_numeric($multiplier) && $spend * (float) $multiplier >= $budget;
    }
}

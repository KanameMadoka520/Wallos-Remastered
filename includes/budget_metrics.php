<?php

function wallos_calculate_budget_metrics($budget, $referenceCost)
{
    $budget = round((float) $budget, 2);
    $referenceCost = round((float) $referenceCost, 2);

    if ($budget <= 0) {
        return null;
    }

    $remaining = max(0, round($budget - $referenceCost, 2));
    $usedPercent = $budget > 0 ? min(100, round(($referenceCost / $budget) * 100, 2)) : 0.0;
    $overAmount = max(0, round($referenceCost - $budget, 2));

    return [
        'budget' => $budget,
        'reference_cost' => $referenceCost,
        'remaining' => $remaining,
        'used_percent' => $usedPercent,
        'over_amount' => $overAmount,
    ];
}

function wallos_calculate_yearly_budget_metrics($yearlyBudget, $actualPaid, $projectedRemaining, $standardizedReference = 0.0)
{
    $yearlyBudget = round((float) $yearlyBudget, 2);
    $actualPaid = round((float) $actualPaid, 2);
    $projectedRemaining = round((float) $projectedRemaining, 2);
    $standardizedReference = round((float) $standardizedReference, 2);

    if ($yearlyBudget <= 0) {
        return null;
    }

    $projectedTotal = round($actualPaid + $projectedRemaining, 2);
    $remaining = max(0, round($yearlyBudget - $projectedTotal, 2));
    $usedPercent = $yearlyBudget > 0 ? min(100, round(($projectedTotal / $yearlyBudget) * 100, 2)) : 0.0;
    $overAmount = max(0, round($projectedTotal - $yearlyBudget, 2));

    return [
        'budget' => $yearlyBudget,
        'actual_paid' => $actualPaid,
        'projected_remaining' => $projectedRemaining,
        'projected_total' => $projectedTotal,
        'standardized_reference' => $standardizedReference,
        'remaining' => $remaining,
        'used_percent' => $usedPercent,
        'over_amount' => $overAmount,
    ];
}

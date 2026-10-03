<?php

namespace Bizzsol\ApprovalMatrix\Services;

/**
 * Workflow `conditions` / step `skip_condition` JSON:
 *   {"amount_min": 0, "amount_max": 100000, "attributes": {"purchase_type": ["foreign"]}}
 * Amount bounds are inclusive; null / missing = unbounded. Empty conditions always match.
 */
class ConditionMatcher
{
    public static function matches(?array $conditions, array $ctx): bool
    {
        if (empty($conditions)) {
            return true;
        }

        $hasAmountBound = isset($conditions['amount_min']) || isset($conditions['amount_max']);
        if ($hasAmountBound) {
            if (! isset($ctx['amount'])) {
                return false; // amount-based workflow cannot apply to a document with no amount
            }
            $amount = (float) $ctx['amount'];
            if (isset($conditions['amount_min']) && $amount < (float) $conditions['amount_min']) {
                return false;
            }
            if (isset($conditions['amount_max']) && $amount > (float) $conditions['amount_max']) {
                return false;
            }
        }

        foreach (($conditions['attributes'] ?? []) as $key => $allowed) {
            if (! in_array($ctx['attributes'][$key] ?? null, (array) $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /** Do two condition sets accept at least one common document? (amount ranges only; attributes treated as overlapping unless disjoint). */
    public static function overlaps(?array $a, ?array $b): bool
    {
        $aMin = (float) ($a['amount_min'] ?? -INF);
        $aMax = (float) ($a['amount_max'] ?? INF);
        $bMin = (float) ($b['amount_min'] ?? -INF);
        $bMax = (float) ($b['amount_max'] ?? INF);

        if ($aMin > $bMax || $bMin > $aMax) {
            return false;
        }

        foreach (array_intersect_key($a['attributes'] ?? [], $b['attributes'] ?? []) as $key => $values) {
            if (empty(array_intersect((array) $values, (array) $b['attributes'][$key]))) {
                return false;
            }
        }

        return true;
    }
}

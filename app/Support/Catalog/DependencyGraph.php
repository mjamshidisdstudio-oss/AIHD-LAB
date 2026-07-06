<?php

namespace App\Support\Catalog;

use App\Models\OptionDependency;
use App\Models\ServiceInput;

/**
 * Cycle detection for the two dependency graphs a service version carries:
 *  - input visibility  (service_inputs.depends_on_input_id, a single parent)
 *  - option gating      (option_dependencies, many parents per option)
 *
 * Both must stay acyclic or the form can never finish resolving which fields to
 * show. Each check asks "would adding THIS edge close a loop?" by walking from
 * the proposed parent back up its own ancestors; reaching the source means the
 * edge would form a cycle. A visited set makes the walk safe even if a stray
 * loop already exists in the data.
 */
class DependencyGraph
{
    /**
     * Would pointing $input->depends_on_input_id at $targetId create a cycle
     * (a self-reference included)?
     */
    public static function inputEdgeCreatesCycle(ServiceInput $input, ?string $targetId): bool
    {
        if ($targetId === null) {
            return false;
        }

        $sourceId = (string) $input->getKey();
        $seen = [];
        $cursor = $targetId;

        while ($cursor !== null) {
            if ($cursor === $sourceId) {
                return true;
            }
            if (isset($seen[$cursor])) {
                break; // pre-existing loop that does not involve the source
            }
            $seen[$cursor] = true;
            $cursor = ServiceInput::whereKey($cursor)->value('depends_on_input_id');
        }

        return false;
    }

    /**
     * Would adding "option $optionId depends on $parentOptionId" create a cycle
     * in the option-gating graph (a self-edge included)? $ignoreId excludes a row
     * being updated so an edge can be repointed without tripping over itself.
     */
    public static function optionEdgeCreatesCycle(
        string $optionId,
        string $parentOptionId,
        ?string $ignoreId = null,
    ): bool {
        if ($optionId === $parentOptionId) {
            return true;
        }

        $seen = [];
        $frontier = [$parentOptionId];

        while ($frontier !== []) {
            $current = array_pop($frontier);
            if ($current === $optionId) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;

            $parents = OptionDependency::query()
                ->where('option_id', $current)
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->pluck('parent_option_id');

            foreach ($parents as $parent) {
                $frontier[] = (string) $parent;
            }
        }

        return false;
    }
}

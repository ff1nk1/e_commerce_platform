<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Category;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a category parent that would create a cycle in the tree.
 *
 * Two failure modes are covered:
 *  - the category is set as its own parent;
 *  - the proposed parent is a descendant of the category (re-parenting a
 *    node under one of its own children), which makes getTreeIds() recurse
 *    forever.
 *
 * On update the category under validation is read from the route so the
 * whole ancestor chain (including a pre-existing cycle) can be walked safely.
 */
final class CategoryParentIsAcyclic implements ValidationRule
{
    private const MAX_DEPTH = 100;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_numeric($value)) {
            // Non-numeric values are rejected by the `exists` rule.
            return;
        }

        $parentId = (int) $value;
        $current = $this->currentCategory();

        // A category cannot be its own parent.
        if ($current instanceof Category && $parentId === (int) $current->getKey()) {
            $fail('Категория не может быть родителем самой себя.');

            return;
        }

        if ($this->wouldLoop($parentId, $current?->getKey())) {
            $fail('Категория не может входить в собственную цепочку предков (обнаружен цикл).');
        }
    }

    private function currentCategory(): ?Category
    {
        $route = request()->route();
        if ($route === null) {
            return null;
        }

        $category = $route->parameter('category');

        return $category instanceof Category ? $category : null;
    }

    /**
     * Walk up from the proposed parent. A cycle exists when we either reach the
     * category being edited or revisit a node (a cycle already present in the DB).
     */
    private function wouldLoop(int $parentId, int|string|null $currentId): bool
    {
        $visited = [];
        $nodeId = $parentId;

        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            if ($currentId !== null && $nodeId === (int) $currentId) {
                return true;
            }

            if (isset($visited[$nodeId])) {
                return true;
            }

            $visited[$nodeId] = true;

            $nextParent = Category::query()->whereKey($nodeId)->value('parent_id');
            if ($nextParent === null) {
                return false;
            }

            $nodeId = (int) $nextParent;
        }

        // Abnormally deep chain — treat as a cycle to fail closed.
        return true;
    }
}

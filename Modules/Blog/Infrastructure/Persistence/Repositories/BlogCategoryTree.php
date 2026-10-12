<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Persistence\Repositories;

use Modules\Blog\Domain\Models\BlogCategory;

/**
 * The Blog category hierarchy, loaded once per request (scoped binding) as a
 * lightweight id → {parent, name, slug, active} map and walked in memory both
 * ways: upwards for breadcrumbs and cycle checks, downwards for "posts in this
 * category and its sub-categories". Blog categories are a small editorial set,
 * so one query beats a recursive query per lookup.
 */
final class BlogCategoryTree
{
    /** @var array<int, array{parent_id: ?int, name: string, slug: string, is_active: bool}>|null */
    private ?array $nodes = null;

    public function forget(): void
    {
        $this->nodes = null;
    }

    /** @return list<int> the category itself plus every descendant, at any depth */
    public function descendantsOf(int $categoryId, bool $activeOnly = false): array
    {
        $nodes = $this->nodes();
        $children = [];
        foreach ($nodes as $id => $node) {
            if ($node['parent_id'] !== null) {
                $children[$node['parent_id']][] = $id;
            }
        }

        $result = [];
        $stack = [$categoryId];
        while ($stack !== []) {
            $id = array_pop($stack);
            if (! isset($nodes[$id]) || in_array($id, $result, true)) {
                continue;
            }
            if ($activeOnly && ! $nodes[$id]['is_active']) {
                continue;
            }
            $result[] = $id;
            foreach ($children[$id] ?? [] as $childId) {
                $stack[] = $childId;
            }
        }

        return $result;
    }

    /**
     * Root-first chain ending with the category itself.
     *
     * @return list<array{id: int, name: string, slug: string, is_active: bool}>
     */
    public function ancestry(int $categoryId): array
    {
        $nodes = $this->nodes();
        $chain = [];
        $seen = [];
        $id = $categoryId;

        while ($id !== null && isset($nodes[$id]) && ! isset($seen[$id])) {
            $seen[$id] = true;
            array_unshift($chain, ['id' => $id, 'name' => $nodes[$id]['name'], 'slug' => $nodes[$id]['slug'], 'is_active' => $nodes[$id]['is_active']]);
            $id = $nodes[$id]['parent_id'];
        }

        return $chain;
    }

    /** Would making `$newParentId` the parent of `$categoryId` create a cycle? */
    public function wouldCreateCycle(int $categoryId, ?int $newParentId): bool
    {
        if ($newParentId === null) {
            return false;
        }

        if ($newParentId === $categoryId) {
            return true;
        }

        foreach ($this->ancestry($newParentId) as $ancestor) {
            if ($ancestor['id'] === $categoryId) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array{parent_id: ?int, name: string, slug: string, is_active: bool}> */
    private function nodes(): array
    {
        if ($this->nodes !== null) {
            return $this->nodes;
        }

        $this->nodes = [];
        foreach (BlogCategory::query()->get(['id', 'parent_id', 'name', 'slug', 'is_active']) as $category) {
            $this->nodes[(int) $category->id] = [
                'parent_id' => $category->parent_id !== null ? (int) $category->parent_id : null,
                'name' => (string) $category->name,
                'slug' => (string) $category->slug,
                'is_active' => (bool) $category->is_active,
            ];
        }

        return $this->nodes;
    }
}

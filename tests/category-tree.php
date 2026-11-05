<?php
declare(strict_types=1);

/**
 * Lightweight regression test for Core\CategoryTree. It uses synthetic rows,
 * so it does not touch data/cms.sqlite.
 *
 * Run: php tests/category-tree.php
 */

require __DIR__ . '/../core/CategoryTree.php';

use Core\CategoryTree;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$categories = [
    ['id' => 1, 'parent_id' => 0, 'name' => 'Root A', 'slug' => 'root-a'],
    ['id' => 2, 'parent_id' => 1, 'name' => 'Child B', 'slug' => 'child-b'],
    ['id' => 3, 'parent_id' => 2, 'name' => 'Grandchild C', 'slug' => 'grandchild-c'],
    ['id' => 4, 'parent_id' => 0, 'name' => 'Root D', 'slug' => 'root-d'],
    ['id' => 5, 'parent_id' => 999, 'name' => 'Orphan E', 'slug' => 'orphan-e'],
    ['id' => 6, 'parent_id' => 7, 'name' => 'Cycle F', 'slug' => 'cycle-f'],
    ['id' => 7, 'parent_id' => 6, 'name' => 'Cycle G', 'slug' => 'cycle-g'],
];

$flat = CategoryTree::flatten($categories);
$byId = [];
foreach ($flat as $row) {
    $byId[(int) $row['id']] = $row;
}

assertSameValue(7, count($flat), 'all categories are returned, including orphans and cycles');
assertSameValue(0, $byId[1]['depth'], 'root depth');
assertSameValue(1, $byId[2]['depth'], 'child depth');
assertSameValue(2, $byId[3]['depth'], 'grandchild depth');
assertSameValue(0, $byId[5]['parent_id'], 'missing parent is promoted to root');
assertSameValue(0, $byId[6]['depth'], 'cycle is promoted to root');

$tree = CategoryTree::tree($categories);
assertSameValue(4, count($tree), 'tree roots include normal roots, orphan and cycle');
$rootA = null;
foreach ($tree as $node) {
    if ((int) $node['id'] === 1) {
        $rootA = $node;
    }
}
assertSameValue(true, $rootA !== null, 'root A exists');
assertSameValue(2, (int) $rootA['children'][0]['id'], 'root A has child B');
assertSameValue(3, (int) $rootA['children'][0]['children'][0]['id'], 'child B has grandchild C');

assertSameValue([2, 3], CategoryTree::descendantIds($categories, 1), 'descendants are collected recursively');
assertSameValue([], CategoryTree::descendantIds($categories, 999), 'missing category has no descendants');
assertSameValue(false, CategoryTree::isValidParent($categories, 1, 1), 'category cannot be its own parent');
assertSameValue(false, CategoryTree::isValidParent($categories, 1, 3), 'category cannot move under a descendant');
assertSameValue(false, CategoryTree::isValidParent($categories, 0, 999), 'missing parent is rejected');
assertSameValue(true, CategoryTree::isValidParent($categories, 3, 1), 'existing ancestor is a valid parent');

echo "OK category tree\n";

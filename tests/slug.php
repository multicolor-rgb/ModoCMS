<?php
declare(strict_types=1);

require __DIR__ . '/../core/Slug.php';

use Core\Slug;

function assertSlug(string $expected, string $input, string $message): void
{
    $actual = Slug::slugify($input);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: {$expected}\nActual:   {$actual}\n");
        exit(1);
    }
}

assertSlug('zazolc-gesla-jazn', 'Zażółć gęślą jaźń', 'Polish diacritics are transliterated');
assertSlug('swiateczne-and-nowe', 'Świąteczne & Nowe', 'ampersand and Polish letters');
assertSlug('cafe-deja-vu', 'Café déjà vu', 'common Latin diacritics');
assertSlug('to-jest-test', '  To   jest   test!  ', 'whitespace and punctuation collapse');
assertSlug('produkt-2', 'Produkt #2', 'digits are preserved');

echo "OK slug\n";

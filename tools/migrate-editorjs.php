<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require dirname(__DIR__) . '/core/bootstrap.php';

use Core\EditorJsMigration;

if (EditorJsMigration::isDone()) {
    echo "Editor.js migration already completed.\n";
    exit(0);
}

try {
    $report = EditorJsMigration::run();
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($report as $table => $count) {
    echo $table . ': ' . $count . "\n";
}

echo "Done.\n";

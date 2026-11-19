<?php
// Recreates the preview images for files uploaded before previews were stored in Telegram.
// The site also does this lazily the first time a preview is shown; this script does it for every
// file in one run.
//
// On the server, from the site's root:
//   php scripts/backfill-thumbs.php [--limit=100]
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

$limit = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = (int) $m[1];
}

set_time_limit(0);

$rows = q_all(
    "SELECT * FROM files WHERE mime LIKE 'image/%' ORDER BY id" . ($limit > 0 ? ' LIMIT ' . $limit : '')
);
$checked = 0;
$made = 0;
$failed = 0;
$gone = 0;

foreach ($rows as $row) {
    $file = file_row($row);
    // Same limit as on-demand generation: originals this big aren't downloaded for a preview
    if ((int) $file['size'] > 200 * 1024 * 1024) {
        echo "file {$file['id']}: too large for a preview, skipped\n";
        continue;
    }
    $missing = array_filter(['og', 'grid'], fn(string $kind): bool => get_thumb($file, $kind) === null);
    if (!$missing) continue;
    $checked++;
    try {
        foreach ($missing as $kind) {
            if (ensure_thumbnail($file, $kind)) {
                $made++;
                echo "file {$file['id']}: $kind preview stored\n";
            } else {
                $failed++;
                echo "file {$file['id']}: $kind preview could not be made\n";
            }
        }
    } catch (TelegramGone $e) {
        // The original was deleted in the Telegram app; drop the stale record
        $gone++;
        discard_file($file);
        echo "file {$file['id']}: no longer in Telegram, record removed\n";
    }
}

printf(
    "Done: %d file(s) needed previews, %d stored, %d failed, %d removed as deleted in Telegram.\n",
    $checked, $made, $failed, $gone
);
exit($failed > 0 ? 1 : 0);

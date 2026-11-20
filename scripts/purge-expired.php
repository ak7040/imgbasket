<?php
// Deletes uploads whose "delete after" period has passed, from Telegram and the database.
// The site also does this in small batches from normal traffic, and refuses expired links on
// access, so this script is not strictly required. Run it from cron to clean up promptly:
//
//   php scripts/purge-expired.php [--limit=500]
//
// --limit caps how many files a single run removes (default 500, the site's own batches are 10).
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

$limit = 500;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = max(1, (int) $m[1]);
}

set_time_limit(0);

$removed = purge_expired_files($limit);
printf("Done: %d expired file(s) removed%s.\n", $removed, $removed >= $limit ? ' (limit reached, run again)' : '');
exit(0);

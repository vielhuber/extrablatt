<?php
declare(strict_types=1);

require dirname(path: __DIR__, levels: 2) . '/src/Extrablatt.php';
require dirname(path: __DIR__, levels: 2) . '/src/Podcast.php';

(new \vielhuber\extrablatt\Extrablatt(rootDir: (string) getenv('PODCAST_TEST_ROOT')))->run();

<?php

declare(strict_types=1);

use Tracewick\Sink\SpoolFile;

require __DIR__ . '/vendor/autoload.php';

[, $directory, $phase] = $argv;
$files = glob($directory . '/*/*.spool') ?: [];
if ($files === []) {
    throw new RuntimeException('No Swoole profiles reached the spool.');
}

$frames = [
    'firstRequest' => false,
    'secondRequest' => false,
    'sampleBeforeStop' => false,
];
foreach ($files as $file) {
    $bytes = file_get_contents($file);
    if ($bytes === false) {
        throw new RuntimeException('Could not read a spool profile.');
    }
    $profile = SpoolFile::fromBytes($bytes);
    if ($profile->application !== 'tracewick-swoole' || $profile->labels !== ['runtime' => 'swoole']) {
        throw new RuntimeException('Unexpected process labels: ' . json_encode($profile->labels));
    }
    // The v2 compact-stacks string table stores frame names as raw strings.
    foreach ($frames as $frame => $found) {
        $frames[$frame] = $found || str_contains($profile->payload, $frame);
    }
}

if (!$frames['firstRequest'] || !$frames['secondRequest'] || $frames['sampleBeforeStop'] !== ($phase === 'stopped')) {
    throw new RuntimeException('Unexpected sampled frames for phase ' . $phase . ': ' . json_encode($frames));
}

printf("Verified %s spool without per-request labels.\n", $phase);

<?php

declare(strict_types=1);

use Tracewick\Sink\SpoolFile;

require getcwd() . '/vendor/autoload.php';

[, $directory, $application] = $argv;
$profiles = [];
foreach (glob($directory . '/*/*.spool') ?: [] as $file) {
    $bytes = file_get_contents($file);
    if ($bytes === false) {
        throw new RuntimeException('Could not read a spool profile.');
    }
    $profile = SpoolFile::fromBytes($bytes);
    if ($profile->application !== $application || $profile->payload === '') {
        throw new RuntimeException('Invalid worker spool profile.');
    }
    $profiles[] = $profile;
}
usort($profiles, static fn(SpoolFile $left, SpoolFile $right): int => $left->startNs <=> $right->startNs);
$labels = array_map(static fn(SpoolFile $profile): array => $profile->labels, $profiles);
if ($labels !== [['route' => 'items.show'], ['route' => 'items.failed'], [], ['route' => 'health.show']]) {
    throw new RuntimeException('Unexpected route labels: ' . json_encode($labels));
}

echo "Verified four isolated profiles and graceful worker shutdown.\n";

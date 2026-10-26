<?php

declare(strict_types=1);
use PHPUnit\Runner\Version;

if ($argc !== 4 || ! in_array($argv[2], ['backend-sqlite', 'backend-postgres'], true)) {
    throw new RuntimeException('Usage: backend-summary.php CLOVER TARGET OUTPUT');
}
if (! extension_loaded('pcov')) {
    throw new RuntimeException('PCOV is required in the dedicated CI coverage job');
}
$root = dirname(__DIR__, 2);
require $root.'/backend/vendor/autoload.php';
$application = realpath($root.'/backend/app');
$xml = simplexml_load_file($argv[1], SimpleXMLElement::class, LIBXML_NONET);
if (! $xml || ! $application || ! $xml->project) {
    throw new RuntimeException('Coverage report unavailable');
}
$files = [];
foreach ($xml->xpath('//file') as $node) {
    $path = realpath((string) $node['name']);
    if (! $path || ! str_starts_with($path, $application.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Unexpected coverage source');
    }
    $lines = [];
    foreach ($node->line as $line) {
        if ((string) $line['type'] === 'stmt') {
            $number = (int) $line['num'];
            $lines[$number] = ($lines[$number] ?? false) || (int) $line['count'] > 0;
        }
    }
    $files[] = [
        'file' => 'backend/app/'.str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($application) + 1)),
        'lines' => ['covered' => count(array_filter($lines)), 'total' => count($lines)],
        'uncovered_lines' => array_keys(array_filter($lines, fn (bool $covered): bool => ! $covered)),
    ];
}
$expected = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($application, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getExtension() === 'php') {
        $expected[] = 'backend/app/'.str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($application) + 1));
    }
}
$reported = array_column($files, 'file');
sort($expected);
sort($reported);
if ($expected !== $reported) {
    throw new RuntimeException('Unexecuted application files omitted from coverage');
}
$covered = array_sum(array_column(array_column($files, 'lines'), 'covered'));
$total = array_sum(array_column(array_column($files, 'lines'), 'total'));
if ($total === 0 || $covered > $total) {
    throw new RuntimeException('Invalid coverage denominator');
}
$git = function (string $arguments) use ($root): string {
    $command = 'git -C '.escapeshellarg($root).' '.$arguments;
    exec($command, $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('Cannot identify measured source');
    }

    return trim(implode("\n", $output));
};
$summary = [
    'schema_version' => 1, 'target' => $argv[2], 'scope' => 'backend/app/**/*.php; includes uncovered files',
    'commit' => $git('rev-parse HEAD'), 'tree' => $git('rev-parse HEAD^{tree}'),
    'working_tree_dirty' => $git('status --porcelain') !== '',
    'measured_at' => gmdate('c'), 'tool' => 'PHP '.PHP_VERSION.' / PHPUnit '.Version::id().' / PCOV '.phpversion('pcov'),
    'totals' => ['lines' => ['covered' => $covered, 'total' => $total, 'percent' => round(100 * $covered / $total, 2)]],
    'files' => $files,
];
if (! is_dir(dirname($argv[3])) && ! mkdir(dirname($argv[3]), 0700, true)) {
    throw new RuntimeException('Cannot create summary directory');
}
if (file_put_contents($argv[3], json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n") === false) {
    throw new RuntimeException('Cannot write coverage summary');
}
echo $argv[2].' coverage: '.$covered.'/'.$total.' lines'.PHP_EOL;

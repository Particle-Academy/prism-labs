<?php

declare(strict_types=1);
use Prism\Acp\Version;

require dirname(__DIR__).'/vendor/autoload.php';
$expected = require __DIR__.'/acp-release.php';

$installed = json_decode((string) file_get_contents(dirname(__DIR__).'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
$package = array_values(array_filter($installed['packages'], fn (array $entry): bool => $entry['name'] === 'particle-academy/prism-acp'))[0] ?? null;
if ($package === null || ($package['installation-source'] ?? null) !== 'dist'
    || ($package['dist']['type'] ?? null) !== 'zip'
    || ltrim($package['version'] ?? '', 'v') !== $expected['version']
    || ($package['source']['reference'] ?? null) !== $expected['source_sha']
    || ($package['dist']['reference'] ?? null) !== $expected['source_sha']
    || $expected['version'] !== Version::VERSION) {
    throw new RuntimeException('the ACP consumer is not running the expected downloaded release');
}
$registry = json_decode((string) file_get_contents('https://repo.packagist.org/p2/particle-academy/prism-acp.json', false,
    stream_context_create(['http' => ['timeout' => 15, 'header' => "Cache-Control: no-cache\r\nAccept: application/json\r\n"]])), true, flags: JSON_THROW_ON_ERROR);
$releases = $registry['packages']['particle-academy/prism-acp'] ?? [];
$latest = null;
foreach ($releases as $release) {
    $version = ltrim($release['version'], 'v');
    if ($latest === null || version_compare($version, $latest['version'], '>')) {
        $latest = ['version' => $version, 'sha' => $release['source']['reference'] ?? null];
    }
}
if ($latest === null || $latest['version'] !== $expected['version'] || $latest['sha'] !== $package['source']['reference']) {
    throw new RuntimeException('a newer or changed ACP release requires a reviewed consumer update');
}
file_put_contents(dirname(__DIR__).'/dogfood-acp-release.json', json_encode([
    'version' => $package['version'], 'source_sha' => $package['source']['reference'],
    'tag' => $expected['tag'],
    'dist_reference' => $package['dist']['reference'], 'installation_source' => $package['installation-source'],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
echo 'ACP release consumer verified: '.$package['version']."\n";

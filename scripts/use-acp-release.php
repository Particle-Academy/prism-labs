<?php

declare(strict_types=1);

// CI's release-consumer lane: resolve the Packagist release instead of the moving path checkout.
if (getenv('GITHUB_ACTIONS') !== 'true') {
    throw new RuntimeException('release dependency switching is restricted to isolated CI');
}
$file = dirname(__DIR__).'/composer.json';
$manifest = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
$manifest['repositories'] = array_values(array_filter($manifest['repositories'], fn (array $repository): bool => ($repository['url'] ?? null) !== '../prism-acp'));
$manifest['require']['particle-academy/prism-acp'] = '0.1.0-alpha.1';
file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

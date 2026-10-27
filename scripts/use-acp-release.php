<?php

declare(strict_types=1);

// CI's release-consumer lane: resolve the Packagist release instead of the moving path checkout.
if (getenv('GITHUB_ACTIONS') !== 'true') {
    throw new RuntimeException('release dependency switching is restricted to isolated CI');
}
$file = dirname(__DIR__).'/composer.json';
$manifest = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
$mode = $argv[1] ?? '--published';
if (! in_array($mode, ['--source', '--published'], true)) {
    throw new RuntimeException('select --source or --published ACP resolution');
}
if ($mode === '--published') {
    $manifest['repositories'] = array_values(array_filter($manifest['repositories'], fn (array $repository): bool => ($repository['url'] ?? null) !== '../prism-acp'));
}
$manifest['require']['particle-academy/prism-acp'] = $mode === '--source' ? 'dev-main as 0.1.0-alpha.5' : '0.1.0-alpha.5';
file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

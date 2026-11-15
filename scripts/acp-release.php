<?php

declare(strict_types=1);

// Reviewed release identity, shared by dependency switching and artifact verification.
$release = json_decode((string) file_get_contents(__DIR__.'/acp-release.json'), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($release) || count($release) !== 3 || array_diff(['version', 'tag', 'source_sha'], array_keys($release)) !== []
    || ! is_string($release['version']) || ! preg_match('/^\d+\.\d+\.\d+$/D', $release['version'])
    || $release['tag'] !== 'v'.$release['version']
    || ! is_string($release['source_sha']) || ! preg_match('/^[a-f0-9]{40}$/D', $release['source_sha'])) {
    throw new RuntimeException('ACP release metadata must identify one reviewed stable version, tag and commit');
}

return $release;

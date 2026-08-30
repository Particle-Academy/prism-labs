<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Models\BenchmarkLane;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class RemotionRenderer
{
    public function __construct(private LaneWorkspace $workspaces, private LaneActivity $activity) {}

    /** @return array<string, mixed> */
    public function render(BenchmarkLane $lane, string $entry, string $composition, string $output): array
    {
        $entry = $this->relativeSourcePath($entry);
        $output = $this->relativeOutputPath($output);

        if (preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,79}\z/', $composition) !== 1) {
            throw new \InvalidArgumentException('Remotion composition id contains unsupported characters.');
        }

        $workspace = $this->workspaces->workspace($lane);
        $workspace->size($entry);
        $workspace->write($output, '');
        $root = $workspace->root();
        if (! is_string($root)) {
            throw new RuntimeException('Remotion rendering requires a local lane workspace.');
        }

        $entryPath = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry);
        $outputPath = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $output);
        if (! is_file($entryPath)) {
            throw new \InvalidArgumentException("Remotion entry file [{$entry}] does not exist.");
        }
        $runtime = base_path('tools/remotion-runtime');
        $cli = $runtime.'/node_modules/@remotion/cli/remotion-cli.js';
        if (! is_file($cli)) {
            throw new RuntimeException('The pinned Remotion CLI is not installed. Run npm install in prism-labs.');
        }

        $this->activity->record($lane, 'remotion.render.started', 'Rendering '.$composition.'.', ['entry' => $entry, 'output' => $output]);
        $render = new Process([PHP_OS_FAMILY === 'Windows' ? 'node.exe' : 'node', $cli, 'render', $entryPath, $composition, $outputPath, '--codec=h264'], $runtime, $this->safeEnvironment(), null, 300);
        $render->run();

        if (! $render->isSuccessful() || ! is_file($outputPath)) {
            $message = trim($render->getErrorOutput()."\n".$render->getOutput());
            throw new RuntimeException('Remotion render failed: '.mb_substr($message, -6000));
        }

        $receipt = $this->inspect($outputPath, $output, $entry, $composition);
        $this->workspaces->workspace($lane)->write('.plabs/remotion-render.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->activity->record($lane, 'remotion.render.completed', 'Rendered and verified '.$output.'.', $receipt);

        return $receipt;
    }

    /** @return array<string, mixed> */
    private function inspect(string $absolute, string $output, string $entry, string $composition): array
    {
        $binary = base_path('tools/remotion-runtime/node_modules/@remotion/compositor-win32-x64-msvc/ffprobe.exe');
        if (PHP_OS_FAMILY !== 'Windows') {
            $binary = 'ffprobe';
        }
        $probe = new Process([$binary, '-v', 'error', '-show_entries', 'format=duration,size:stream=codec_name,width,height', '-of', 'json', $absolute], null, $this->safeEnvironment(), null, 30);
        $probe->mustRun();
        $metadata = json_decode($probe->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $stream = is_array($metadata['streams'][0] ?? null) ? $metadata['streams'][0] : [];

        return [
            'ok' => true,
            'kind' => 'remotion.render',
            'entry' => $entry,
            'composition' => $composition,
            'output' => $output,
            'duration_seconds' => (float) ($metadata['format']['duration'] ?? 0),
            'width' => (int) ($stream['width'] ?? 0),
            'height' => (int) ($stream['height'] ?? 0),
            'codec' => (string) ($stream['codec_name'] ?? ''),
            'bytes' => filesize($absolute),
            'sha256' => hash_file('sha256', $absolute),
        ];
    }

    /** @return array<string, string|false> */
    private function safeEnvironment(): array
    {
        $keys = PHP_OS_FAMILY === 'Windows'
            ? ['PATH', 'SystemRoot', 'TEMP', 'TMP', 'LOCALAPPDATA', 'APPDATA', 'USERPROFILE']
            : ['PATH', 'HOME', 'TMPDIR'];
        $inherited = getenv();
        $environment = is_array($inherited) ? array_fill_keys(array_keys($inherited), false) : [];
        foreach ($keys as $key) {
            $value = getenv($key);
            if (is_string($value)) {
                $environment[$key] = $value;
            }
        }

        return $environment;
    }

    private function relativeSourcePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path, '/'));
        if (preg_match('/\A(?:[A-Za-z0-9_-]+\/)*[A-Za-z0-9_.-]+\.tsx?\z/', $path) !== 1) {
            throw new \InvalidArgumentException('Remotion entry must be a relative .ts or .tsx file path.');
        }

        return $path;
    }

    private function relativeOutputPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path, '/'));
        if (preg_match('/\A(?:[A-Za-z0-9_-]+\/)*[A-Za-z0-9_.-]+\.mp4\z/', $path) !== 1) {
            throw new \InvalidArgumentException('Remotion output must be a relative .mp4 file path.');
        }

        return $path;
    }
}

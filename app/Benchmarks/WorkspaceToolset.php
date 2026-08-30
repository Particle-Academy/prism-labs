<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Models\BenchmarkLane;
use Prism\Harness\Sessions\Session;
use Prism\Prism\Tool;
use Prism\Workspace\WorkspaceManager;

final readonly class WorkspaceToolset
{
    public function __construct(private WorkspaceManager $workspaces, private LaneActivity $activity, private RemotionRenderer $remotion) {}

    /** @return list<Tool> */
    public function forSession(Session $session): array
    {
        $lane = $this->lane($session);
        if (! $lane instanceof BenchmarkLane) {
            return [];
        }
        $workspace = $this->workspaces->for($session);

        return [
            (new Tool)->as('workspace_list')->for('List the real files currently present in this benchmark lane workspace.')
                ->withStringParameter('directory', 'Relative directory, or empty for the root.', required: false)
                ->using(fn (string $directory = ''): string => json_encode($workspace->list($directory)->take(2000)->map(fn ($entry): array => ['path' => $entry->path, 'kind' => $entry->isDirectory ? 'directory' : 'file', 'size' => $entry->size])->values()->all(), JSON_THROW_ON_ERROR)),
            (new Tool)->as('workspace_read')->for('Read one text file from this benchmark lane workspace.')
                ->withStringParameter('path', 'Relative file path.')->using(fn (string $path): string => $workspace->read($path)),
            (new Tool)->as('workspace_write')->for('Create or replace one text file. Every claimed deliverable must use this tool; prose is not an artifact.')
                ->withStringParameter('path', 'Relative file path.')->withStringParameter('content', 'Complete text file contents.')
                ->using(function (string $path, string $content) use ($workspace, $lane): string {
                    $workspace->write($path, $content);
                    $this->activity->record($lane, 'file.written', 'Wrote '.$path, ['path' => $path, 'bytes' => strlen($content)]);

                    return json_encode(['ok' => true, 'path' => $path, 'bytes' => strlen($content)], JSON_THROW_ON_ERROR);
                }),
            (new Tool)->as('workspace_delete')->for('Delete one file from this benchmark lane workspace.')
                ->withStringParameter('path', 'Relative file path.')
                ->using(function (string $path) use ($workspace, $lane): string {
                    $workspace->delete($path);
                    $this->activity->record($lane, 'file.deleted', 'Deleted '.$path, ['path' => $path]);

                    return json_encode(['ok' => true, 'path' => $path], JSON_THROW_ON_ERROR);
                }),
            (new Tool)->as('remotion_render')->for('Render and independently verify a Remotion composition in this lane. Follow the Harness-owned remotion skill. This is a bounded renderer, not a general shell.')
                ->withStringParameter('entry', 'Relative .ts or .tsx Remotion entry file.')
                ->withStringParameter('composition', 'Composition id registered by the entry file.')
                ->withStringParameter('output', 'Relative .mp4 output path.')
                ->using(fn (string $entry, string $composition, string $output): string => json_encode(
                    $this->remotion->render($lane, $entry, $composition, $output),
                    JSON_THROW_ON_ERROR,
                )),
        ];
    }

    private function lane(Session $session): ?BenchmarkLane
    {
        if (preg_match('/^benchmark:[0-9a-f-]{36}:([0-9a-f-]{36})$/i', $session->scope(), $matches) !== 1) {
            return null;
        }

        return BenchmarkLane::query()->find($matches[1]);
    }
}

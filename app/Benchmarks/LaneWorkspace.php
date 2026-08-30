<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Lab\LabSession;
use App\Models\BenchmarkLane;
use Prism\Workspace\Workspace;
use Prism\Workspace\WorkspaceManager;

final readonly class LaneWorkspace
{
    public function __construct(private LabSession $sessions, private WorkspaceManager $workspaces) {}

    /** @return list<array{path:string,name:string,kind:string,size?:int,mtime?:string,hasChildren?:bool}> */
    public function files(BenchmarkLane $lane): array
    {
        return $this->workspace($lane)->list()->take(2000)->map(fn ($entry): array => [
            'path' => '/'.$entry->path, 'name' => $entry->name(),
            'kind' => $entry->isDirectory ? 'dir' : 'file',
            ...($entry->size === null ? [] : ['size' => $entry->size]),
            ...($entry->lastModified === null ? [] : ['mtime' => date(DATE_ATOM, $entry->lastModified)]),
            ...($entry->isDirectory ? ['hasChildren' => true] : []),
        ])->values()->all();
    }

    /** @return array{path:string,content:string,language:string,size:int} */
    public function read(BenchmarkLane $lane, string $path): array
    {
        $relative = trim($path, '/');
        $workspace = $this->workspace($lane);
        if ($workspace->size($relative) > 1_000_000) {
            throw new \RuntimeException('File exceeds the 1 MB viewer limit.');
        }
        $content = $workspace->read($relative);
        if (str_contains($content, "\0")) {
            throw new \RuntimeException('Binary files cannot be opened in the code viewer.');
        }

        return ['path' => '/'.$relative, 'content' => $content, 'language' => pathinfo($relative, PATHINFO_EXTENSION) ?: 'text', 'size' => strlen($content)];
    }

    public function provision(BenchmarkLane $lane): string
    {
        $workspace = $this->workspace($lane);
        $path = $workspace->root() ?? $workspace->address();
        $lane->forceFill(['workspace_path' => $path])->save();

        return $path;
    }

    /** @return array{path:string,absolute:string,mime:string,size:int} */
    public function media(BenchmarkLane $lane, string $path): array
    {
        $relative = trim(str_replace('\\', '/', $path), '/');
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $mimes = [
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'pdf' => 'application/pdf',
        ];
        if (! isset($mimes[$extension])) {
            throw new \InvalidArgumentException('This file type is not supported by the media viewer.');
        }

        $workspace = $this->workspace($lane);
        $root = $workspace->root();
        if ($root === null) {
            throw new \RuntimeException('Media streaming requires a local workspace driver.');
        }
        $root = realpath($root);
        if ($root === false) {
            throw new \RuntimeException('The lane workspace root is unavailable.');
        }
        $absolute = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($absolute === false || ! is_file($absolute) || ! str_starts_with($absolute, $root.DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Media path must identify a file inside this lane workspace.');
        }

        return ['path' => '/'.$relative, 'absolute' => $absolute, 'mime' => $mimes[$extension], 'size' => filesize($absolute) ?: 0];
    }

    public function workspace(BenchmarkLane $lane): Workspace
    {
        return $this->workspaces->for($this->sessions->resolveScope('benchmark:'.$lane->benchmark_run_id.':'.$lane->id));
    }
}

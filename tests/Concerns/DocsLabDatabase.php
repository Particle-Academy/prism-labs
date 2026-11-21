<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

/**
 * The docs lab's tests run on the forced in-memory SQLite database and NOTHING
 * else: the connection is asserted to be `:memory:` before any migration, and
 * a plain `migrate` builds the schema in that fresh memory database (no
 * migrate:fresh, no wipe; there is nothing to wipe). Live cost reservations
 * must COMMIT, so these tests do not wrap themselves in RefreshDatabase's
 * outer transaction. Artefacts go to a per-test directory under
 * storage/framework/testing, removed afterwards.
 */
trait DocsLabDatabase
{
    protected string $docsLabArtefacts = '';

    protected function setUpDocsLab(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->docsLabArtefacts = storage_path('framework/testing/docs-lab-'.bin2hex(random_bytes(6)));
        config(['docs-lab.artefacts' => $this->docsLabArtefacts]);
        $directory = $this->docsLabArtefacts;
        $this->beforeApplicationDestroyed(function () use ($directory): void {
            app(Filesystem::class)->deleteDirectory($directory);
        });
    }
}

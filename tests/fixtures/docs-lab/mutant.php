<?php

declare(strict_types=1);
use App\DocsLab\DocsLabRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Prism\AgentDocs\Pictures\PictureRegistry;

/*
 * G-316 mutation fixture, run as a CHILD process by ScriptedLaneMutationTest.
 *
 *   php tests/fixtures/docs-lab/mutant.php <owned dir> <mutant.php> <scenario>
 *
 * Loads a broken copy of the agent's PictureRegistry BEFORE Composer's
 * autoloader can load the real one, boots the Lab on an empty env file with
 * in-memory SQLite (asserted before any migration), runs the scripted lane on
 * one scenario and prints the run as JSON. It refuses any directory that is
 * not a docs-lab fixture directory under storage/framework/testing.
 */

$repo = dirname(__DIR__, 3);
[$directory, $mutant, $scenario] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
$parent = realpath($repo.'/storage/framework/testing');
$resolved = realpath($directory);
if ($resolved === false || $parent === false || dirname($resolved) !== $parent || is_link($directory)
    || preg_match('/^docs-lab-mutant-[a-f0-9]{12}$/D', basename($resolved)) !== 1
    || ! is_file($resolved.'/fixture.env') || filesize($resolved.'/fixture.env') !== 0
    || dirname((string) realpath($mutant)) !== $resolved
    || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:' || getenv('LAB_DB_DATABASE') !== ':memory:'
    || preg_match('/^[A-Z][0-9]$/D', $scenario) !== 1) {
    fwrite(STDERR, "Refusing a non-owned docs-lab mutation process.\n");
    exit(2);
}

$mutantLoader = static function (string $class) use ($mutant): void {
    if ($class === 'Prism\\AgentDocs\\Pictures\\PictureRegistry') {
        require $mutant;
    }
};
require $repo.'/vendor/autoload.php';
// Composer prepends its own loader, so ours goes in front of it afterwards.
spl_autoload_register($mutantLoader, prepend: true);

$app = require $repo.'/bootstrap/app.php';
$app->useEnvironmentPath($resolved)->loadEnvironmentFrom('fixture.env');
$app->make(Kernel::class)->bootstrap();
$db = app('db')->connection();
if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
    fwrite(STDERR, "Refusing: not the in-memory test database.\n");
    exit(2);
}
config(['docs-lab.artefacts' => $resolved.'/artefacts']);
Http::preventStrayRequests();
Artisan::call('migrate', ['--force' => true]);

$loaded = (new ReflectionClass(PictureRegistry::class))->getFileName();
$run = app(DocsLabRunner::class)->scripted($scenario);
echo json_encode([
    'mutant_loaded' => realpath((string) $loaded) === realpath($mutant),
    'status' => $run->status,
    'results' => $run->results->map(fn ($r) => ['scenario' => $r->scenario, 'passed' => $r->passed,
        'failed' => collect($r->checks)->reject(fn ($c) => $c['passed'])->pluck('check')->values()->all()])->all(),
], JSON_UNESCAPED_SLASHES), "\n";

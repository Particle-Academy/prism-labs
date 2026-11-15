<?php

use App\Lab\LabDatabase;

return [
    // Persist the namespace in the config cache as well. A pre-isolation cache
    // has no marker and must be cleared before this application can boot.
    'version' => 1,
    'names' => LabDatabase::names(),
    'sandbox_database' => env('LAB_SANDBOX_DB_DATABASE', env('DB_DATABASE')),
    'sandbox_sqlite' => env('LAB_SANDBOX_SQLITE_DATABASE', base_path('../prism-sandbox/database/database.sqlite')),
];

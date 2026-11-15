<?php

use App\Providers\AppServiceProvider;
use App\Providers\LabCouncilServiceProvider;
use App\Providers\LabPreflightServiceProvider;
use App\Providers\TeamServiceProvider;

return [
    AppServiceProvider::class,
    LabCouncilServiceProvider::class,
    LabPreflightServiceProvider::class,
    TeamServiceProvider::class,
];

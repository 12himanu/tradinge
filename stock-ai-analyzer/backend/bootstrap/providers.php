<?php

declare(strict_types=1);
use App\Providers\AppServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    DomainServiceProvider::class,
    HorizonServiceProvider::class,
];

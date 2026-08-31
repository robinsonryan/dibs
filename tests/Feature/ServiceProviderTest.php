<?php

declare(strict_types=1);

use RobinsonRyan\Dibs\DibsServiceProvider;

it('registers the service provider', function (): void {
    expect(app()->getProviders(DibsServiceProvider::class))->not->toBeEmpty();
});

it('merges the package config', function (): void {
    expect(config('dibs.table_prefix'))->toBe('dibs_');
});

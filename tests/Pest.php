<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\Organization;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\Room;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\User;
use RobinsonRyan\Dibs\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');

// Concurrency tests need real commits so a second connection can see the rows;
// they truncate instead of wrapping each test in a transaction.
uses(TestCase::class, DatabaseTruncation::class)->in('Concurrency');

expect()->extend('toBeUuidV7', fn () => $this->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/'));

function user(string $name = 'Someone'): User
{
    return User::create(['name' => $name]);
}

function room(string $name = 'Room 1'): Room
{
    return Room::create(['name' => $name]);
}

function organization(string $name = 'Ward'): Organization
{
    return Organization::create(['name' => $name]);
}

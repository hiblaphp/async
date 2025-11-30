<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

use Hibla\Async\Async;
use Hibla\Async\Handlers\ConcurrencyHandler;
use Hibla\Async\Handlers\PromiseCollectionHandler;
use Hibla\Async\Mutex;
use Hibla\EventLoop\EventLoop;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

pest()->extend(Tests\TestCase::class)->in('Feature');
pest()->extend(Tests\TestCase::class)->in('Unit');
pest()->extend(Tests\TestCase::class)->in('Integration');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBePromise', function () {
    return $this->toBeInstanceOf(PromiseInterface::class);
});

expect()->extend('toBeCancellablePromise', function () {
    return $this->toBeInstanceOf(CancellablePromiseInterface::class);
});

expect()->extend('toBeSettled', function () {
    $promise = $this->value;

    if (! ($promise instanceof PromiseInterface)) {
        throw new InvalidArgumentException('Value must be a Promise');
    }

    return $this->toBe($promise->isPending());
});

function waitForPromise(PromiseInterface $promise): mixed
{
    return $promise->await();
}

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function mutex(): Mutex
{
    return new Mutex();
}

function delayedValue($value, $delayMs)
{
    return new Promise(function ($resolve) use ($value, $delayMs) {
        scheduleResolve($resolve, $value, $delayMs);
    });
}

function delayedReject($error, $delayMs)
{
    return new Promise(function ($resolve, $reject) use ($error, $delayMs) {
        scheduleReject($reject, $error, $delayMs);
    });
}

function scheduleResolve($resolve, $value, $delayMs)
{
    Loop::addTimer($delayMs / 1000, function () use ($resolve, $value) {
        $resolve($value);
    });
}

function scheduleReject($reject, $error, $delayMs)
{
    Loop::addTimer($delayMs / 1000, function () use ($reject, $error) {
        $reject($error);
    });
}

function concurrencyHandler(): ConcurrencyHandler
{
    return new ConcurrencyHandler();
}

function promiseCollectionHandler(): PromiseCollectionHandler
{
    return new PromiseCollectionHandler();
}

function complexScenarioHandlers(): array
{
    return [
        'concurrencyHandler' => new ConcurrencyHandler(),
        'collectionHandler' => new PromiseCollectionHandler(),
    ];
}
/**
 * Resets all core singletons and clears test state.
 *
 * This function is the single source of truth for test setup. By calling it
 * in each test file's `beforeEach` hook, we ensure perfect test isolation.
 */
function resetEventLoop()
{
    EventLoop::reset();
    Async::reset();
    Promise::reset();
}

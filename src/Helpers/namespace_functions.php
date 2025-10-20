<?php

namespace Hibla;

use Hibla\Async\Async;
use Hibla\Async\Timer;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Check if the current execution context is within a PHP Fiber.
 *
 * @return bool True if executing within a fiber, false otherwise
 */
function in_fiber(): bool
{
    return Async::inFiber();
}

/**
 * Convert a regular function into an async function that returns a Promise.
 *
 * @template TReturn The return type of the async function
 * 
 * @param  callable(): TReturn  $asyncFunction  The function to convert to async
 * @return PromiseInterface<TReturn> A promise that resolves to the return value
 *
 * @example
 * $promise = async(function() {
 *     $result = await(http_get('https://api.example.com'));
 *     return $result;
 * });
 */
function async(callable $asyncFunction): PromiseInterface
{
    return Async::async($asyncFunction)();
}

/**
 * Suspends the current fiber until the promise is fulfilled or rejected.
 *
 * **Context-Aware Behavior:**
 * - Inside fiber context: Suspends the fiber, yielding control to the event loop
 * - Outside fiber context: Blocks execution using EventLoop until promise settles
 *
 * This method is the heart of the await pattern. When in a fiber, it pauses
 * execution without blocking, allowing other tasks to run. When outside a fiber,
 * it automatically falls back to blocking mode for convenience.
 *
 * ```php
 * // Inside async context - suspends fiber (non-blocking)
 * async(function() {
 *     $user = await($getUserPromise);
 *     $posts = await($getPostsPromise);
 *     return compact('user', 'posts');
 * });
 *
 * // Outside async context - blocks until resolved
 * $result = await($promise);
 * ```
 * @template TValue The expected type of the resolved value from the promise.
 *
 * @param  PromiseInterface<TValue>  $promise  The promise to await.
 * @return TValue The resolved value of the promise.
 *
 * @throws \Exception If the promise is rejected, this method throws the rejection reason.
 */
function await(PromiseInterface $promise): mixed
{
    return Async::await($promise);
}

/**
 * Create a promise that resolves after a specified time delay.
 *
 * @param  float  $seconds  Number of seconds to delay
 * @return CancellablePromiseInterface<null> A promise that resolves after the delay
 */
function delay(float $seconds): CancellablePromiseInterface
{
    return Timer::delay($seconds);
}

/**
 * Pause execution for a specified duration.
 *
 * @param  float  $seconds  Number of seconds to sleep (supports fractional seconds)
 * @return null Always returns null after the sleep duration
 */
function sleep(float $seconds): null
{
    return Timer::sleep($seconds);
}

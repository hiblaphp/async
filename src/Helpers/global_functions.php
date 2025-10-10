<?php

use Hibla\Async\Async;
use Hibla\Async\Timer;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

if (! function_exists('in_fiber')) {
    /**
     * Check if the current execution context is within a PHP Fiber.
     *
     * This is essential for determining if async operations can be performed
     * safely or if they need to be wrapped in a fiber context first.
     *
     * @return bool True if executing within a fiber, false otherwise
     */
    function in_fiber(): bool
    {
        return Async::inFiber();
    }
}

if (! function_exists('async')) {
    /**
     * Convert a regular function into an async function that returns a Promise.
     *
     * The returned function will execute the original function within a fiber
     * context, enabling it to use async operations like await. This is the
     * primary method for creating async functions from synchronous code.
     *
     * @param  callable  $asyncFunction  The function to convert to async
     * @return PromiseInterface<mixed> An async version that returns a Promise
     *
     * - $asyncFunc = async(function($data) {
     *     $result = await(http_get('https://api.example.com'));
     *     return $result;
     * });
     */
    function async(callable $asyncFunction): PromiseInterface
    {
        /** @var PromiseInterface<mixed> $result */
        $result = Async::async($asyncFunction)();

        return $result;
    }
}

if (! function_exists('await')) {
    /**
     * Suspends the current fiber until the promise is fulfilled or rejected.
     *
     * **Context-Aware Behavior:**
     * - Inside fiber context (e.g., within async()): Suspends the fiber without blocking
     * - Outside fiber context: Blocks execution using EventLoop until promise settles
     *
     * This is the primary method for awaiting promises in async code. When called
     * inside a fiber, it yields control to the event loop, allowing other tasks to run.
     * When called outside a fiber, it falls back to blocking mode for convenience.
     *
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
     * 
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
}

if (! function_exists('delay')) {
    /**
     * Create a promise that resolves after a specified time delay.
     *
     * This creates a timer-based promise that will resolve with null after
     * the specified delay. Useful for creating pauses in async execution
     * without blocking the event loop.
     *
     * @param  float  $seconds  Number of seconds to delay
     * @return CancellablePromiseInterface<null> A promise that resolves after the delay
     *
     * @example
     * await(delay(2.5)); // Wait 2.5 seconds
     */
    function delay(float $seconds): CancellablePromiseInterface
    {
        return Timer::delay($seconds);
    }
}

<?php

namespace Hibla\Async;

use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Static API for core asynchronous operations and fiber management.
 *
 * This API provides a simplified interface to fiber-based asynchronous programming
 * capabilities, focusing on execution control, function transformation, and async
 * workflow management. It handles automatic initialization of the underlying async
 * infrastructure and manages singleton instances internally.
 *
 * For promise creation and collection utilities, see the Promise class.
 * For timer-based operations, see the Timer class.
 */
final class Async
{
    /**
     * @var AsyncOperations|null Cached instance of core async operations handler
     */
    private static ?AsyncOperations $asyncOps = null;

    /**
     * Get the singleton instance of AsyncOperations with lazy initialization.
     *
     * @return AsyncOperations The core async operations handler
     */
    protected static function getAsyncOperations(): AsyncOperations
    {
        if (self::$asyncOps === null) {
            self::$asyncOps = new AsyncOperations();
        }

        return self::$asyncOps;
    }

    /**
     * Reset all cached instances to their initial state.
     *
     * This method clears all singleton instances, forcing fresh initialization
     * on next access. Primarily useful for testing scenarios where clean state
     * is required between test cases.
     */
    public static function reset(): void
    {
        self::$asyncOps = null;
    }

    /**
     * Check if the current execution context is within a PHP Fiber.
     *
     * This is essential for determining if async operations can be performed
     * safely or if they need to be wrapped in a fiber context first.
     *
     * @return bool True if executing within a fiber, false otherwise
     */
    public static function inFiber(): bool
    {
        return self::getAsyncOperations()->inFiber();
    }

    /**
     * Convert a regular function into an async function that returns a Promise.
     *
     * The returned function will execute the original function within a fiber
     * context, enabling it to use async operations like await. This is the
     * primary method for creating async functions from synchronous code.
     *
     * @template TReturn The return type of the async function
     *
     * @param  callable(): TReturn  $asyncFunction  The function to convert to async
     * @return callable(): PromiseInterface<TReturn> An async version that returns a Promise
     */
    public static function async(callable $asyncFunction): callable
    {
        return self::getAsyncOperations()->async($asyncFunction);
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
     *
     * @template TValue The expected type of the resolved value from the promise.
     *
     * @param  PromiseInterface<TValue>  $promise  The promise to await.
     * @return TValue The resolved value of the promise.
     *
     * @throws \Exception If the promise is rejected, this method throws the rejection reason.
     */
    public static function await(PromiseInterface $promise): mixed
    {
        return self::getAsyncOperations()->await($promise);
    }
}

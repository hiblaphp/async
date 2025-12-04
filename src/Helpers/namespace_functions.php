<?php

declare(strict_types=1);

namespace Hibla;

use Fiber;
use Hibla\Async\Handlers\AsyncExecutionHandler;
use Hibla\Async\Handlers\AwaitHandler;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Check if the current execution context is within a PHP Fiber.
 *
 * @return bool True if executing within a fiber, false otherwise
 */
function inFiber(): bool
{
    return Fiber::getCurrent() !== null;
}

/**
 * Convert a regular function into an async function that returns a Promise.
 *
 * This wraps the callable in a Fiber, allowing it to use `await()` for non-blocking
 * asynchronous operations. The function executes immediately and returns a Promise
 * that resolves to the function's return value.
 *
 * **Performance Note:** Each `async()` call creates a new Fiber. Avoid unnecessary
 * wrapping when you're only awaiting a single promise:
 *
 * ```php
 * // ✗ Unnecessary - creates extra fiber overhead
 * $result = await(async(function() {
 *     return await($somePromise);
 * }));
 *
 * // ✓ Better - await the promise directly
 * $result = await($somePromise);
 * ```
 *
 * Use `async()` when you need to:
 * - Await multiple promises sequentially
 * - Perform operations between awaits
 * - Create reusable async workflows
 *
 * ```php
 * // ✓ Good use - multiple awaits and logic
 * $promise = async(function() {
 *     $user = await(fetchUser(1));
 *     $posts = await(fetchPosts($user->id));
 *     $comments = await(fetchComments($posts));
 *     $ratings = await(fetchRatings($user->id));
 *     return processData($user, $posts, $comments, $ratings);
 * });
 * ```
 *
 * @template TReturn The return type of the async function
 *
 * @param  callable(): TReturn  $function  The function to convert to async
 * @return PromiseInterface<TReturn> A promise that resolves to the return value
 */
function async(callable $function): PromiseInterface
{
    /** @var AsyncExecutionHandler|null $handler */
    static $handler = null;

    if ($handler === null) {
        $handler = new AsyncExecutionHandler();
    }

    return $handler->async($function);
}

/**
 * Wrap a callable to return a new callable that executes asynchronously.
 *
 * @template TReturn The return type of the function
 *
 * @param  callable(): TReturn  $function  The function to wrap
 * @return callable(): PromiseInterface<TReturn> An async version of the function
 *
 * ```php
 * $asyncGreet = asyncFn(function($name) {
 *     return "Hello, $name!";
 * });
 *
 * $promise = $asyncGreet('World');
 * $result = await($promise); // "Hello, World!"
 * ```
 */
function asyncFn(callable $function): callable
{
    /** @var AsyncExecutionHandler|null $handler */
    static $handler = null;

    if ($handler === null) {
        $handler = new AsyncExecutionHandler();
    }

    return $handler->asyncFn($function);
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
    /** @var AwaitHandler|null $handler */
    static $handler = null;

    if ($handler === null) {
        $handler = new AwaitHandler();
    }

    return $handler->await($promise);
}

/**
 * Pause execution for the specified duration.
 *
 * **Context-Aware Behavior:**
 * - Inside fiber/async context: Suspends the current fiber non-blocking, allowing other fibers to continue executing
 * - Outside fiber/async context: Blocks the entire script execution (like traditional sleep())
 *
 * This function combines `delay()` and `await()` for convenient async-aware sleeping.
 * When used inside an async context, it yields control to the event loop, enabling
 * concurrent operations. When used outside async context, it falls back to blocking behavior.
 *
 * @param  float  $seconds  Number of seconds to sleep (supports fractional seconds, e.g., 0.5 for 500ms)
 * @return void
 *
 * ```php
 * // Inside async context - non-blocking (other fibers continue running)
 * async(function() {
 *     echo "Start\n";
 *     sleep(1.5); // Pauses this fiber for 1.5 seconds
 *     echo "After 1.5 seconds\n";
 * });
 * ```
 *
 * ```php
 * // Outside async context - blocking (entire script pauses)
 * echo "Start\n";
 * sleep(2); // Blocks everything for 2 seconds
 * echo "After 2 seconds\n";
 * ```
 *
 * ```php
 * // Multiple concurrent sleeps
 * async(function() {
 *     echo "Task 1 start\n";
 *     sleep(2);
 *     echo "Task 1 done\n";
 * });
 *
 * async(function() {
 *     echo "Task 2 start\n";
 *     sleep(1);
 *     echo "Task 2 done\n"; // This completes first!
 * });
 * ```
 */
function sleep(float $seconds): void
{
    await(delay($seconds));
}

<?php

namespace Hibla\Async\Interfaces;

use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Throwable;

/**
 * Provides core asynchronous operations for fiber-based async programming.
 *
 * This interface defines the essential methods for working with promises, fibers,
 * and asynchronous operations in a fiber-based async environment.
 */
interface AsyncOperationsInterface
{
    /**
     * Checks if the current execution context is within a fiber.
     *
     * @return bool True if executing within a fiber, false otherwise.
     */
    public function inFiber(): bool;

    /**
     * Creates a resolved promise with the given value.
     *
     * @template TValue
     *
     * @param  TValue  $value  The value to resolve the promise with.
     * @return PromiseInterface<TValue> A promise resolved with the provided value.
     */
    public function resolved(mixed $value): PromiseInterface;

    /**
     * Creates a rejected promise with the given reason.
     *
     * @param  mixed  $reason  The reason for rejection (typically an exception or error message).
     * @return PromiseInterface<mixed> A promise rejected with the provided reason.
     */
    public function rejected(mixed $reason): PromiseInterface;

    /**
     * Wraps a synchronous function to make it asynchronous.
     *
     * The returned callable will execute the original function within a fiber
     * and return a promise that resolves with the function's result.
     *
     * @template TReturn
     * @param  callable(): TReturn  $asyncFunction  The function to wrap asynchronously.
     * @return callable(): PromiseInterface<TReturn> A callable that returns a PromiseInterface.
     */
    public function async(callable $asyncFunction): callable;

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
    public function await(PromiseInterface $promise): mixed;

    /**
     * Creates a promise that resolves with null after the specified delay.
     *
     * @param  float  $seconds  The delay in seconds (supports fractions for milliseconds).
     * @return CancellablePromiseInterface<null> A promise that resolves with null after the delay.
     */
    public function delay(float $seconds): CancellablePromiseInterface;

    /**
     * Waits for all promises to resolve or any to reject.
     *
     * Returns a promise that resolves with an array of all resolved values
     * in the same order as the input promises, or rejects with the first rejection.
     *
     * @template TAllValue
     * @param  array<int|string, PromiseInterface<TAllValue>|callable(): PromiseInterface<TAllValue>>  $promises  Array of PromiseInterface instances.
     * @return PromiseInterface<array<int|string, TAllValue>> A promise that resolves with an array of results.
     */
    public function all(array $promises): PromiseInterface;

    /**
     * Wait for all promises to settle (either resolve or reject).
     *
     * Unlike all(), this method waits for every promise to complete and returns
     * all results, including both successful values and rejection reasons.
     * This method never rejects - it always resolves with an array of settlement results.
     *
     * @template TAllSettledValue
     * @param  array<int|string, PromiseInterface<TAllSettledValue>|callable(): PromiseInterface<TAllSettledValue>>  $promises
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TAllSettledValue, reason?: mixed}>>
     */
    public function allSettled(array $promises): PromiseInterface;

    /**
     * Returns a promise that settles with the first promise to settle.
     *
     * The returned promise will resolve or reject with the value/reason
     * of whichever input promise settles first.
     *
     * @template TRaceValue
     * @param  array<int|string, PromiseInterface<TRaceValue>|callable(): PromiseInterface<TRaceValue>>  $promises  Array of PromiseInterface instances.
     * @return PromiseInterface<TRaceValue> A promise that settles with the first settled promise.
     */
    public function race(array $promises): PromiseInterface;

    /**
     * Wait for any promise in the collection to resolve.
     *
     * Returns a promise that resolves with the value of the first
     * promise that resolves, or rejects if all promises reject.
     *
     * @template TAnyValue
     * @param  array<int|string, PromiseInterface<TAnyValue>|callable(): PromiseInterface<TAnyValue>>  $promises  Array of promises to wait for
     * @return PromiseInterface<TAnyValue> A promise that resolves with the first settled value
     */
    public function any(array $promises): PromiseInterface;

    /**
     * Add a timeout to a promise operation.
     *
     * @template TTimeoutValue
     * @param  PromiseInterface<TTimeoutValue>  $promise  The promise to add timeout to
     * @param  float  $seconds  Timeout duration in seconds
     * @return PromiseInterface<TTimeoutValue>
     */
    public function timeout(PromiseInterface $promise, float $seconds): PromiseInterface;

    /**
     * Executes multiple async tasks with controlled concurrency.
     *
     * Limits the number of simultaneously executing tasks while ensuring
     * all tasks eventually complete.
     *
     * @template TConcurrentValue
     * @param  array<int|string, callable(): (TConcurrentValue|PromiseInterface<TConcurrentValue>)>  $tasks  Array of callable tasks that return promises or values.
     * @param  int  $concurrency  Maximum number of concurrent executions (default: 10).
     * @return PromiseInterface<array<int|string, TConcurrentValue>> A promise that resolves with an array of all results when all tasks complete.
     */
    public function concurrent(array $tasks, int $concurrency = 10): PromiseInterface;

    /**
     * Execute multiple tasks in batches with a concurrency limit.
     *
     * This method processes tasks in smaller batches, allowing for
     * controlled concurrency and resource management.
     *
     * @template TBatchValue
     * @param  array<int|string, callable(): (TBatchValue|PromiseInterface<TBatchValue>)>  $tasks  Array of tasks (callables that return promises or values) to execute.
     * @param  int  $batchSize  Size of each batch to process concurrently.
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch.
     * @return PromiseInterface<array<int|string, TBatchValue>> A promise that resolves with all results.
     */
    public function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface;

    /**
     * Execute multiple tasks concurrently with a specified concurrency limit and wait for all to settle.
     *
     * Similar to concurrent(), but waits for all tasks to complete and returns settlement results.
     * This method never rejects - it always resolves with an array of settlement results.
     *
     * @template TConcurrentSettledValue
     * @param  array<int|string, callable(): (TConcurrentSettledValue|PromiseInterface<TConcurrentSettledValue>)>  $tasks  Array of tasks (callables) to execute
     * @param  int  $concurrency  Maximum number of concurrent executions
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TConcurrentSettledValue, reason?: mixed}>> A promise that resolves with settlement results
     */
    public function concurrentSettled(array $tasks, int $concurrency = 10): PromiseInterface;

    /**
     * Execute multiple tasks in batches with a concurrency limit and wait for all to settle.
     *
     * Similar to batch(), but waits for all tasks to complete and returns settlement results.
     * This method never rejects - it always resolves with an array of settlement results.
     *
     * @template TBatchSettledValue
     * @param  array<int|string, callable(): (TBatchSettledValue|PromiseInterface<TBatchSettledValue>)>  $tasks  Array of tasks (callables) to execute
     * @param  int  $batchSize  Size of each batch to process concurrently
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TBatchSettledValue, reason?: mixed}>> A promise that resolves with settlement results
     */
    public function batchSettled(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface;
}
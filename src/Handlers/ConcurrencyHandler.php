<?php

namespace Hibla\Async\Handlers;

use Hibla\EventLoop\EventLoop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use RuntimeException;
use Throwable;

/**
 * Handles concurrent execution of multiple tasks with concurrency limiting.
 *
 * This handler manages the execution of multiple asynchronous tasks while
 * limiting the number of tasks that run simultaneously. This is useful for
 * controlling resource usage and preventing overwhelming external services.
 */
final readonly class ConcurrencyHandler
{
    private AsyncExecutionHandler $executionHandler;
    private AwaitHandler $awaitHandler;

    /**
     * @param  AsyncExecutionHandler  $executionHandler  Handler for async execution
     */
    public function __construct(AsyncExecutionHandler $executionHandler)
    {
        $this->executionHandler = $executionHandler;
        $this->awaitHandler = new AwaitHandler(new FiberContextHandler());
    }

    /**
     * Execute multiple tasks concurrently with a specified concurrency limit.
     *
     * This method runs multiple tasks simultaneously while ensuring that no more
     * than the specified number of tasks run at the same time. Tasks can be either
     * callable functions or existing Promise instances. Promise instances will be
     * automatically wrapped to ensure proper concurrency control.
     *
     * @template TConcurrentValue
     * @param  array<int|string, callable(): (TConcurrentValue|PromiseInterface<TConcurrentValue>)>  $tasks  Array of callable tasks that return promises or values.
     * @param  int  $concurrency  Maximum number of concurrent executions (default: 10).
     * @return PromiseInterface<array<int|string, TConcurrentValue>> A promise that resolves with an array of all results when all tasks complete.
     *
     * @throws RuntimeException If a task doesn't return a Promise
     */
    public function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        /** @var Promise<array<int|string, TConcurrentValue>> */
        return new Promise(function (callable $resolve, callable $reject) use ($tasks, $concurrency): void {
            if ($concurrency <= 0) {
                $reject(new \InvalidArgumentException('Concurrency limit must be greater than 0'));

                return;
            }

            if ($tasks === []) {
                $resolve([]);

                return;
            }

            // Preserve original keys in order
            $originalKeys = array_keys($tasks);
            $taskList = array_values($tasks);

            // Process tasks to ensure proper async wrapping
            $processedTasks = [];
            foreach ($taskList as $index => $task) {
                $processedTasks[$index] = $this->wrapTaskForConcurrency($task);
            }

            // Pre-initialize results array to maintain order
            $results = [];
            foreach ($originalKeys as $key) {
                $results[$key] = null; // Placeholder
            }

            $running = 0;
            $completed = 0;
            $total = count($processedTasks);
            $taskIndex = 0;

            $processNext = function () use (
                &$processNext,
                &$processedTasks,
                &$originalKeys,
                &$running,
                &$completed,
                &$results,
                &$total,
                &$taskIndex,
                $concurrency,
                $resolve,
                $reject
            ): void {
                // Start as many tasks as we can up to the concurrency limit
                while ($running < $concurrency && $taskIndex < $total) {
                    $currentIndex = $taskIndex++;
                    $task = $processedTasks[$currentIndex];
                    $originalKey = $originalKeys[$currentIndex];
                    $running++;

                    try {
                        $asyncTask = $this->executionHandler->async($task);
                        $promise = $asyncTask();

                        if (! ($promise instanceof PromiseInterface)) {
                            throw new RuntimeException('Task must return a Promise or be a callable that returns a Promise');
                        }
                    } catch (Throwable $e) {
                        $running--;
                        $reject($e);

                        return;
                    }

                    $promise
                        ->then(function ($result) use (
                            $originalKey,
                            &$results,
                            &$running,
                            &$completed,
                            &$originalKeys,
                            $total,
                            $resolve,
                            $processNext
                        ): void {
                            $results[$originalKey] = $result;
                            $running--;
                            $completed++;

                            if ($completed === $total) {
                                // Build final results in original key order
                                $orderedResults = [];
                                foreach ($originalKeys as $key) {
                                    $orderedResults[$key] = $results[$key];
                                }
                                $resolve($orderedResults);
                            } else {
                                // Schedule next task processing on next tick
                                EventLoop::getInstance()->nextTick($processNext);
                            }
                        })
                        ->catch(function ($error) use (&$running, $reject): void {
                            $running--;
                            $reject($error);
                        })
                    ;
                }
            };

            // Start initial batch of tasks
            EventLoop::getInstance()->nextTick($processNext);
        });
    }

    /**
     * Execute tasks in sequential batches with concurrency within each batch.
     *
     * This method processes tasks in batches sequentially, where each batch
     * runs tasks concurrently up to the specified limit, but waits for the
     * entire batch to complete before starting the next batch. Promise instances
     * will be automatically wrapped to ensure proper concurrency control.
     *
     * @template TBatchValue
     * @param  array<int|string, callable(): (TBatchValue|PromiseInterface<TBatchValue>)>  $tasks  Array of tasks (callables that return promises or values) to execute.
     * @param  int  $batchSize  Size of each batch to process concurrently.
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch.
     * @return PromiseInterface<array<int|string, TBatchValue>> A promise that resolves with all results.
     */
    public function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        /** @var Promise<array<int|string, TBatchValue>> */
        return new Promise(function (callable $resolve, callable $reject) use ($tasks, $batchSize, $concurrency): void {
            if ($batchSize <= 0) {
                $reject(new \InvalidArgumentException('Batch size must be greater than 0'));

                return;
            }

            if ($tasks === []) {
                $resolve([]);

                return;
            }

            $concurrency ??= $batchSize;

            if ($concurrency <= 0) {
                $reject(new \InvalidArgumentException('Concurrency limit must be greater than 0'));

                return;
            }

            // Preserve original keys and wrap tasks
            $originalKeys = array_keys($tasks);
            $taskValues = array_values($tasks);

            // Process tasks to ensure proper async wrapping
            $processedTasks = [];
            foreach ($taskValues as $index => $task) {
                $processedTasks[$index] = $this->wrapTaskForConcurrency($task);
            }

            $batches = array_chunk($processedTasks, $batchSize, false);
            $keyBatches = array_chunk($originalKeys, $batchSize, false);

            // Pre-initialize results to maintain order
            $allResults = [];
            foreach ($originalKeys as $key) {
                $allResults[$key] = null;
            }

            $batchIndex = 0;
            $totalBatches = count($batches);

            $processNextBatch = function () use (
                &$processNextBatch,
                &$batches,
                &$keyBatches,
                &$allResults,
                &$batchIndex,
                &$originalKeys,
                $totalBatches,
                $concurrency,
                $resolve,
                $reject
            ): void {
                if ($batchIndex >= $totalBatches) {
                    // Build final results in original order
                    $orderedResults = [];
                    foreach ($originalKeys as $key) {
                        $orderedResults[$key] = $allResults[$key];
                    }
                    $resolve($orderedResults);

                    return;
                }

                $currentBatch = $batches[$batchIndex];
                $currentKeys = $keyBatches[$batchIndex];

                $batchTasks = array_combine($currentKeys, $currentBatch);

                $this->concurrent($batchTasks, $concurrency)
                    ->then(function ($batchResults) use (
                        &$allResults,
                        &$batchIndex,
                        $processNextBatch
                    ): void {
                        // Merge batch results while maintaining order
                        foreach ($batchResults as $key => $result) {
                            $allResults[$key] = $result;
                        }
                        $batchIndex++;
                        EventLoop::getInstance()->nextTick($processNextBatch);
                    })
                    ->catch(function ($error) use ($reject): void {
                        $reject($error);
                    })
                ;
            };

            EventLoop::getInstance()->nextTick($processNextBatch);
        });
    }

    /**
     * Execute multiple tasks concurrently with a specified concurrency limit and wait for all to settle.
     *
     * Similar to concurrent(), but waits for all tasks to complete (either resolve or reject)
     * and returns settlement results for all tasks. This method never rejects - it always
     * resolves with an array of settlement results.
     *
     * @template TConcurrentSettledValue
     * @param  array<int|string, callable(): (TConcurrentSettledValue|PromiseInterface<TConcurrentSettledValue>)>  $tasks  Array of tasks (callables) to execute
     * @param  int  $concurrency  Maximum number of concurrent executions
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TConcurrentSettledValue, reason?: mixed}>> A promise that resolves with settlement results
     */
    public function concurrentSettled(array $tasks, int $concurrency = 10): PromiseInterface
    {
        /** @var Promise<array<int|string, array{status: 'fulfilled'|'rejected', value?: TConcurrentSettledValue, reason?: mixed}>> */
        return new Promise(function (callable $resolve, callable $reject) use ($tasks, $concurrency): void {
            if ($concurrency <= 0) {
                $reject(new \InvalidArgumentException('Concurrency limit must be greater than 0'));

                return;
            }

            if ($tasks === []) {
                $resolve([]);

                return;
            }

            // Convert tasks to indexed array and preserve original keys
            $taskList = array_values($tasks);
            $originalKeys = array_keys($tasks);

            // Process tasks to ensure proper async wrapping
            $processedTasks = [];
            foreach ($taskList as $index => $task) {
                $processedTasks[$index] = $this->wrapTaskForConcurrency($task);
            }

            // Pre-initialize the results array with all keys to maintain order
            $results = [];
            foreach ($originalKeys as $key) {
                $results[$key] = null; // Placeholder to maintain order
            }

            $running = 0;
            $completed = 0;
            $total = count($processedTasks);
            $taskIndex = 0;

            $processNext = function () use (
                &$processNext,
                &$processedTasks,
                &$originalKeys,
                &$running,
                &$completed,
                &$results,
                &$total,
                &$taskIndex,
                $concurrency,
                $resolve,
            ): void {
                // Start as many tasks as we can up to the concurrency limit
                while ($running < $concurrency && $taskIndex < $total) {
                    $currentIndex = $taskIndex++;
                    $task = $processedTasks[$currentIndex];
                    $originalKey = $originalKeys[$currentIndex];
                    $running++;

                    try {
                        $asyncTask = $this->executionHandler->async($task);
                        $promise = $asyncTask();

                        if (! ($promise instanceof PromiseInterface)) {
                            throw new RuntimeException('Task must return a Promise or be a callable that returns a Promise');
                        }
                    } catch (Throwable $e) {
                        // If task creation fails, treat as rejected settlement
                        $results[$originalKey] = [
                            'status' => 'rejected',
                            'reason' => $e,
                        ];
                        $running--;
                        $completed++;

                        if ($completed === $total) {
                            // Remove null placeholders and return final results
                            $finalResults = [];
                            foreach ($originalKeys as $key) {
                                $finalResults[$key] = $results[$key];
                            }
                            $resolve($finalResults);

                            return;
                        }

                        // Continue processing next task
                        EventLoop::getInstance()->nextTick($processNext);

                        return;
                    }

                    $promise
                        ->then(function ($result) use (
                            $originalKey,
                            &$results,
                            &$running,
                            &$completed,
                            &$originalKeys,
                            $total,
                            $resolve,
                            $processNext
                        ): void {
                            $results[$originalKey] = [
                                'status' => 'fulfilled',
                                'value' => $result,
                            ];
                            $running--;
                            $completed++;

                            if ($completed === $total) {
                                // Build final results in original order
                                $finalResults = [];
                                foreach ($originalKeys as $key) {
                                    $finalResults[$key] = $results[$key];
                                }
                                $resolve($finalResults);
                            } else {
                                // Schedule next task processing on next tick
                                EventLoop::getInstance()->nextTick($processNext);
                            }
                        })
                        ->catch(function ($error) use (
                            $originalKey,
                            &$results,
                            &$running,
                            &$completed,
                            &$originalKeys,
                            $total,
                            $resolve,
                            $processNext
                        ): void {
                            $results[$originalKey] = [
                                'status' => 'rejected',
                                'reason' => $error,
                            ];
                            $running--;
                            $completed++;

                            if ($completed === $total) {
                                // Build final results in original order
                                $finalResults = [];
                                foreach ($originalKeys as $key) {
                                    $finalResults[$key] = $results[$key];
                                }
                                $resolve($finalResults);
                            } else {
                                // Schedule next task processing on next tick
                                EventLoop::getInstance()->nextTick($processNext);
                            }
                        })
                    ;
                }
            };

            // Start initial batch of tasks
            EventLoop::getInstance()->nextTick($processNext);
        });
    }

    /**
     * Execute tasks in sequential batches with concurrency within each batch and wait for all to settle.
     *
     * Similar to batch(), but waits for all tasks to complete (either resolve or reject)
     * and returns settlement results for all tasks. This method never rejects - it always
     * resolves with an array of settlement results.
     *
     * @template TBatchSettledValue
     * @param  array<int|string, callable(): (TBatchSettledValue|PromiseInterface<TBatchSettledValue>)>  $tasks  Array of tasks (callables) to execute
     * @param  int  $batchSize  Size of each batch to process concurrently
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TBatchSettledValue, reason?: mixed}>> A promise that resolves with settlement results
     */
    public function batchSettled(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        /** @var Promise<array<int|string, array{status: 'fulfilled'|'rejected', value?: TBatchSettledValue, reason?: mixed}>> */
        return new Promise(function (callable $resolve, callable $reject) use ($tasks, $batchSize, $concurrency): void {
            if ($batchSize <= 0) {
                $reject(new \InvalidArgumentException('Batch size must be greater than 0'));

                return;
            }

            if ($tasks === []) {
                $resolve([]);

                return;
            }

            $concurrency ??= $batchSize;

            if ($concurrency <= 0) {
                $reject(new \InvalidArgumentException('Concurrency limit must be greater than 0'));

                return;
            }

            // Preserve original keys and wrap tasks
            $originalKeys = array_keys($tasks);
            $taskValues = array_values($tasks);

            // Process tasks to ensure proper async wrapping
            $processedTasks = [];
            foreach ($taskValues as $index => $task) {
                $processedTasks[$index] = $this->wrapTaskForConcurrency($task);
            }

            $batches = array_chunk($processedTasks, $batchSize, false);
            $keyBatches = array_chunk($originalKeys, $batchSize, false);

            // Pre-initialize the results array with all keys to maintain order
            $allResults = [];
            foreach ($originalKeys as $key) {
                $allResults[$key] = null;
            }

            $batchIndex = 0;
            $totalBatches = count($batches);

            $processNextBatch = function () use (
                &$processNextBatch,
                &$batches,
                &$keyBatches,
                &$allResults,
                &$batchIndex,
                &$originalKeys,
                $totalBatches,
                $concurrency,
                $resolve
            ): void {
                if ($batchIndex >= $totalBatches) {
                    // Build final results in original order
                    $orderedResults = [];
                    foreach ($originalKeys as $key) {
                        $orderedResults[$key] = $allResults[$key];
                    }
                    $resolve($orderedResults);

                    return;
                }

                $currentBatch = $batches[$batchIndex];
                $currentKeys = $keyBatches[$batchIndex];

                $batchTasks = array_combine($currentKeys, $currentBatch);

                $this->concurrentSettled($batchTasks, $concurrency)
                    ->then(function ($batchResults) use (
                        &$allResults,
                        &$batchIndex,
                        $processNextBatch
                    ): void {
                        foreach ($batchResults as $key => $result) {
                            $allResults[$key] = $result;
                        }
                        $batchIndex++;
                        EventLoop::getInstance()->nextTick($processNextBatch);
                    })
                ;
            };

            EventLoop::getInstance()->nextTick($processNextBatch);
        });
    }

    /**
     * Wrap a task to ensure proper concurrency control.
     *
     * This method ensures all tasks use the await pattern for proper fiber-based concurrency:
     * - All callables are wrapped to ensure their results are awaited
     * - Promise instances are wrapped with await
     * - Other types are wrapped in a callable
     *
     * @param  callable(): mixed|PromiseInterface<mixed>  $task  The task to wrap
     * @return callable(): mixed A callable that properly defers execution
     */
    private function wrapTaskForConcurrency(mixed $task): callable
    {
        if (is_callable($task)) {
            return function () use ($task) {
                $result = $task();
                if ($result instanceof PromiseInterface) {
                    return $this->awaitHandler->await($result);
                }

                return $result;
            };
        }

        if ($task instanceof PromiseInterface) {
            return function () use ($task) {
                return $this->awaitHandler->await($task);
            };
        }

        return function () use ($task) {
            return $task;
        };
    }
}

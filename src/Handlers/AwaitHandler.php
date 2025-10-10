<?php

namespace Hibla\Async\Handlers;

use Exception;
use Fiber;
use Hibla\Promise\Interfaces\PromiseInterface;
use Throwable;

/**
 * Handles awaiting Promise resolution within Fiber contexts.
 *
 * This handler provides the core await functionality that allows synchronous-style
 * code to wait for asynchronous operations to complete. It works by suspending
 * the current Fiber until the Promise resolves or rejects. If not in a Fiber
 * context, it falls back to using the Promise's own await method.
 */
final readonly class AwaitHandler
{
    private FiberContextHandler $contextHandler;

    /**
     * @param  FiberContextHandler  $contextHandler  Handler for validating fiber context
     */
    public function __construct(FiberContextHandler $contextHandler)
    {
        $this->contextHandler = $contextHandler;
    }

    /**
     * Wait for a Promise to resolve and return its value.
     *
     * **Context-Aware Behavior:**
     * - Inside Fiber context: Suspends the fiber by calling Fiber::suspend() repeatedly
     *   until the promise settles, allowing the event loop to process other tasks
     * - Outside Fiber context: Falls back to the promise's await() method, which blocks
     *   execution using the EventLoop until the promise settles
     *
     * This dual behavior enables the same code to work in both async and sync contexts,
     * providing maximum flexibility while maintaining the benefits of non-blocking
     * execution when possible.
     * ```php
     * // Inside fiber - suspends without blocking
     * async(function() use ($handler, $promise) {
     *     $result = $handler->await($promise); // Non-blocking
     *     return $result;
     * });
     *
     * // Outside fiber - blocks until resolved
     * $result = $handler->await($promise); // Blocking
     * ```
     * 
     * @template TValue The type of the resolved value of the promise.
     *
     * @param  PromiseInterface<TValue>  $promise  The promise to await.
     * @return TValue The resolved value of the promise.
     *
     * @throws Exception|Throwable If the Promise is rejected
     */
    public function await(PromiseInterface $promise): mixed
    {
        // If not in a Fiber context, use the Promise's own await method
        if (! $this->contextHandler->inFiber()) {
            return $promise->await(false);
        }

        $result = null;
        $error = null;
        $completed = false;
        $hasResult = false;

        $promise
            ->then(function ($value) use (&$result, &$completed, &$hasResult) {
                $result = $value;
                $completed = true;
                $hasResult = true;
            })
            ->catch(function ($reason) use (&$error, &$completed) {
                $error = $reason;
                $completed = true;
            })
        ;

        // Suspend the fiber until the promise completes
        while (! $completed) {
            Fiber::suspend();
        }

        if ($error !== null) {
            $errorMessage = match (true) {
                $error instanceof Throwable => throw $error,
                is_string($error) => $error,
                is_object($error) && method_exists($error, '__toString') => (string) $error,
                default => 'Promise rejected with: ' . var_export($error, true)
            };

            if (! ($error instanceof Throwable)) {
                throw new Exception($errorMessage);
            }
        }

        /** @var TValue $result */
        return $result;
    }
}

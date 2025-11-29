<?php

namespace Hibla\Async\Handlers;

use Fiber;
use Hibla\EventLoop\EventLoop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Throwable;

/**
 * Handles the execution of asynchronous operations using PHP Fibers.
 *
 * This handler provides utilities to convert regular functions into async functions,
 * manage fiber execution, and handle error propagation in asynchronous contexts.
 * It's the core component for creating and managing async operations.
 */
final readonly class AsyncExecutionHandler
{
    /**
     * Convert a function into an asynchronous version that returns a Promise.
     *
     * The returned function, when called, will execute the original function
     * inside a Fiber and return a Promise that resolves with the result.
     *
     * @template TReturn The return type of the async function
     *
     * @param  callable(): TReturn  $callback  The function to make asynchronous.
     * @return callable(): PromiseInterface<TReturn> A function that returns a Promise when called.
     */
    public function async(callable $callback): callable
    {
        /** @phpstan-ignore-next-line - Closure generic type cannot be inferred through Promise constructor */
        return function (...$args) use ($callback): PromiseInterface {
            return new Promise(function (callable $resolve, callable $reject) use ($callback, $args) {
                $fiber = new Fiber(function () use ($callback, $args, $resolve, $reject): void {
                    try {
                        $result = $callback(...$args);
                        $resolve($result);
                    } catch (Throwable $e) {
                        $reject($e);
                    }
                });

                EventLoop::getInstance()->addFiber($fiber);
            });
        };
    }
}

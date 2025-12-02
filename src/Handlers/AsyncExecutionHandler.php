<?php

declare(strict_types=1);

namespace Hibla\Async\Handlers;

use Fiber;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Throwable;

final readonly class AsyncExecutionHandler
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $function
     * @return Promise<TReturn>
     */
    public function async(callable $function): PromiseInterface
    {
        /** @var Promise<TReturn> $promise */
        $promise = new Promise(function (callable $resolve, callable $reject) use ($function) {
            $fiber = new Fiber(function () use ($function, $resolve, $reject): void {
                try {
                    $result = $function();
                    $resolve($result);
                } catch (Throwable $e) {
                    $reject($e);
                }
            });

            Loop::addFiber($fiber);
        });

        return $promise;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $function
     * @return callable(): PromiseInterface<TReturn>
     */
    public function asyncFn(callable $function): callable
    {
        return function (mixed ...$args) use ($function): PromiseInterface {
            return $this->async(fn () => $function(...$args));
        };
    }
}

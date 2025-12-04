<?php

declare(strict_types=1);

namespace Hibla\Async\Handlers;

use Exception;
use Fiber;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Throwable;

final readonly class AwaitHandler
{
    /**
     * @template TValue
     *
     * @param  PromiseInterface<TValue>  $promise
     * @return TValue
     */
    public function await(PromiseInterface $promise): mixed
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            return $promise->await(false);
        }

        $result = null;
        $error = null;

        $promise
            ->then(function ($value) use (&$result, $fiber) {
                $result = $value;
                Loop::scheduleFiber($fiber);

                return $value;
            })
            ->catch(function ($reason) use (&$error, $fiber) {
                $error = $reason;
                Loop::scheduleFiber($fiber);

                return $reason;
            })
        ;

        Fiber::suspend();

        if ($error !== null) {
            $errorMessage = match (true) {
                $error instanceof Throwable => throw $error,
                \is_string($error) => $error,
                \is_object($error) && method_exists($error, '__toString') => (string) $error,
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

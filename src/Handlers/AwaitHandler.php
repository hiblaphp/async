<?php

declare(strict_types=1);

namespace Hibla\Async\Handlers;

use Exception;
use Fiber;
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
        // If not in a Fiber context, use the Promise's own await method
        if (Fiber::getCurrent() === null) {
            return $promise->await(false);
        }

        $result = null;
        $error = null;
        $completed = false;

        $promise
            ->then(function ($value) use (&$result, &$completed) {
                $result = $value;
                $completed = true;
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

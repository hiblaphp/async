<?php

declare(strict_types=1);

namespace Hibla\Async\Handlers;

use Exception;
use Fiber;
use Hibla\Cancellation\CancellationToken;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\PromiseCancelledException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Throwable;

final readonly class AwaitHandler
{
    /**
     * @template TValue
     *
     * @param  PromiseInterface<TValue>  $promise
     * @param  CancellationToken|null  $cancellationToken
     * @return TValue
     */
    public function await(PromiseInterface $promise, ?CancellationToken $cancellationToken = null): mixed
    {
        if ($cancellationToken !== null) {
            $cancellationToken->track($promise);
        }

        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            return $promise->wait(false);
        }

        if ($promise->isCancelled()) {
            throw new PromiseCancelledException('Cannot await a cancelled promise');
        }

        $result = null;
        $error = null;

        $promise
            ->then(static function ($value) use (&$result, $fiber) {
                $result = $value;

                Loop::scheduleFiber($fiber);
            })
            ->catch(static function ($reason) use (&$error, $fiber) {
                $error = $reason;

                Loop::scheduleFiber($fiber);
            })
            ->onCancel(static function () use ($fiber) {
                Loop::scheduleFiber($fiber);
            })
        ;

        Fiber::suspend();

        //@phpstan-ignore-next-line Promise can be cancelled midflight
        if ($promise->isCancelled()) {
            throw new PromiseCancelledException('Promise was cancelled during await');
        }

        if ($error !== null) {
            if ($error instanceof Throwable) {
                throw $error;
            }

            $errorMessage = match (true) {
                \is_string($error) => $error,
                \is_object($error) && method_exists($error, '__toString') => (string) $error,
                default => 'Promise rejected with: ' . var_export($error, true)
            };

            throw new Exception($errorMessage);
        }

        /** @var TValue $result */
        return $result;
    }
}

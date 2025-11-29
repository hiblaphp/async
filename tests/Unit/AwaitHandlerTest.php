<?php

use Hibla\Async\Handlers\AsyncExecutionHandler;
use Hibla\Async\Handlers\AwaitHandler;
use Hibla\Async\Handlers\FiberContextHandler;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Promise;

function awaitHandler(): AwaitHandler
{
    return new AwaitHandler(new FiberContextHandler());
}

describe('AwaitHandler', function () {
    it('awaits resolved promise outside fiber context', function () {
        $handler = awaitHandler();
        $promise = new Promise();
        $promise->resolve('test value');

        $result = $handler->await($promise);
        expect($result)->toBe('test value');
    });

    it('awaits rejected promise outside fiber context', function () {
        $handler = awaitHandler();
        $promise = new Promise();
        $promise->reject(new Exception('test error'));

        expect(fn () => $handler->await($promise))
            ->toThrow(Exception::class, 'test error')
        ;
    });

    it('awaits promise inside fiber context using AsyncExecutionHandler', function () {
        $asyncHandler = new AsyncExecutionHandler();

        $asyncFunction = $asyncHandler->async(function () {
            $contextHandler = new FiberContextHandler();
            $awaitHandler = new AwaitHandler($contextHandler);

            $promise = new Promise();
            $promise->resolve('fiber result');

            return $awaitHandler->await($promise);
        });

        $resultPromise = $asyncFunction();
        Loop::run();

        $result = $resultPromise->await();
        expect($result)->toBe('fiber result');
    });

    it('handles string rejection reasons', function () {
        $handler = awaitHandler();
        $promise = new Promise();
        $promise->reject('string error');

        expect(fn () => $handler->await($promise))
            ->toThrow(Exception::class, 'string error')
        ;
    });

    it('handles object rejection reasons with toString', function () {
        $handler = awaitHandler();
        $errorObj = new class () {
            public function __toString(): string
            {
                return 'object error';
            }
        };

        $promise = new Promise();
        $promise->reject($errorObj);

        expect(fn () => $handler->await($promise))
            ->toThrow(Exception::class, 'object error')
        ;
    });
});

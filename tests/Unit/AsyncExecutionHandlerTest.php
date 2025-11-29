<?php

use Hibla\Async\Handlers\AsyncExecutionHandler;

function asyncExecutionHandler(): AsyncExecutionHandler
{
    return new AsyncExecutionHandler();
}

describe('AsyncExecutionHandler', function () {
    it('converts a regular function to async function', function () {
        $handler = asyncExecutionHandler();
        $asyncFunc = $handler->async(fn () => 'test result');

        expect($asyncFunc)->toBeCallable();

        $promise = $asyncFunc();
        expect($promise)->toBePromise();

        $result = waitForPromise($promise);
        expect($result)->toBe('test result');
    });

    it('handles function with arguments', function () {
        $handler = asyncExecutionHandler();
        $asyncFunc = $handler->async(fn ($a, $b) => $a + $b);

        $promise = $asyncFunc(5, 3);
        $result = waitForPromise($promise);

        expect($result)->toBe(8);
    });

    it('handles exceptions in async functions', function () {
        $handler = asyncExecutionHandler();
        $asyncFunc = $handler->async(function () {
            throw new Exception('Test exception');
        });

        $promise = $asyncFunc();

        expect(fn () => waitForPromise($promise))
            ->toThrow(Exception::class, 'Test exception')
        ;
    });

    it('preserves function return types', function () {
        $handler = asyncExecutionHandler();
        $asyncFunc = $handler->async(fn () => ['key' => 'value']);

        $promise = $asyncFunc();
        $result = waitForPromise($promise);

        expect($result)->toBe(['key' => 'value']);
    });

    it('handles null return values', function () {
        $handler = asyncExecutionHandler();
        $asyncFunc = $handler->async(fn () => null);

        $promise = $asyncFunc();
        $result = waitForPromise($promise);

        expect($result)->toBeNull();
    });
});

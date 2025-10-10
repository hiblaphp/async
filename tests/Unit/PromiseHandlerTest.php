<?php

use Hibla\Async\Handlers\PromiseHandler;
use Hibla\Promise\Interfaces\PromiseInterface;

function promiseHandler(): PromiseHandler
{
    return new PromiseHandler();
}

describe('PromiseHandler', function () {
    it('creates resolved promise', function () {
        $handler = promiseHandler();
        $promise = $handler->resolve('test value');

        expect($promise)->toBeInstanceOf(PromiseInterface::class);
        expect($promise->isResolved())->toBe(true);
        expect($promise->await())->toBe('test value');
    });

    it('creates rejected promise', function () {
        $handler = promiseHandler();
        $promise = $handler->reject('test error');

        expect($promise)->toBePromise();
        expect($promise->isRejected())->toBe(true);

        expect(fn() => $promise->await())
            ->toThrow(Exception::class);
    });

    it('creates empty promise', function () {
        $handler = promiseHandler();
        $promise = $handler->createEmpty();

        expect($promise)->toBeInstanceOf(PromiseInterface::class);
        expect($promise->isPending())->toBe(true);
    });

    it('resolves with different data types', function () {
        $handler = promiseHandler();
        $testCases = [
            'string' => 'hello',
            'integer' => 42,
            'array' => ['key' => 'value'],
            'object' => (object)['prop' => 'value'],
            'null' => null,
            'boolean' => true,
        ];

        foreach (array_values($testCases) as $value) {
            $promise = $handler->resolve($value);
            expect($promise->await())->toBe($value);
        }
    });
});
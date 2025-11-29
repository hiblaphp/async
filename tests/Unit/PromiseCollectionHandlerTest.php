<?php

use Hibla\Async\Exceptions\AggregateErrorException;
use Hibla\Async\Exceptions\TimeoutException;
use Hibla\Promise\Promise;

describe('PromiseCollectionHandler', function () {
    it('resolves all promises', function () {
        $handler = promiseCollectionHandler();
        $promise1 = new Promise();
        $promise1->resolve('result1');

        $promise2 = new Promise();
        $promise2->resolve('result2');

        $promises = [$promise1, $promise2];

        $promise = $handler->all($promises);
        $results = waitForPromise($promise);

        expect($results)->toBe(['result1', 'result2']);
    });

    it('rejects if any promise rejects', function () {
        $handler = promiseCollectionHandler();
        $promise1 = new Promise();
        $promise1->resolve('success');

        $promise2 = new Promise();
        $promise2->reject(new Exception('failure'));

        $promises = [$promise1, $promise2];

        $promise = $handler->all($promises);

        expect(fn () => waitForPromise($promise))
            ->toThrow(Exception::class, 'failure')
        ;
    });

    it('handles empty promise array', function () {
        $handler = promiseCollectionHandler();
        $promise = $handler->all([]);
        $results = waitForPromise($promise);

        expect($results)->toBe([]);
    });

    it('settles all promises', function () {
        $handler = promiseCollectionHandler();
        $promise1 = new Promise();
        $promise1->resolve('success');

        $promise2 = new Promise();
        $promise2->reject(new Exception('failure'));

        $promises = [$promise1, $promise2];

        $promise = $handler->allSettled($promises);
        $results = waitForPromise($promise);

        expect($results)->toHaveCount(2);
        expect($results[0]['status'])->toBe('fulfilled');
        expect($results[0]['value'])->toBe('success');
        expect($results[1]['status'])->toBe('rejected');
        expect($results[1]['reason'])->toBeInstanceOf(Exception::class);
    });

    it('races promises', function () {
        $handler = promiseCollectionHandler();
        $fastPromise = new Promise();
        $fastPromise->resolve('fast');

        $slowPromise = new Promise();

        $promise = $handler->race([$slowPromise, $fastPromise]);
        $result = waitForPromise($promise);

        expect($result)->toBe('fast');
    });

    it('handles timeout', function () {
        $handler = promiseCollectionHandler();
        $slowPromise = new Promise();

        $promise = $handler->timeout($slowPromise, 0.05);

        expect(fn () => waitForPromise($promise))
            ->toThrow(TimeoutException::class)
        ;
    });

    it('resolves any promise', function () {
        $handler = promiseCollectionHandler();
        $promise1 = new Promise();
        $promise1->reject(new Exception('fail1'));

        $promise2 = new Promise();
        $promise2->resolve('success');

        $promise3 = new Promise();
        $promise3->reject(new Exception('fail2'));

        $promises = [$promise1, $promise2, $promise3];

        $promise = $handler->any($promises);
        $result = waitForPromise($promise);

        expect($result)->toBe('success');
    });

    it('rejects when all promises reject', function () {
        $handler = promiseCollectionHandler();
        $promise1 = new Promise();
        $promise1->reject(new Exception('fail1'));

        $promise2 = new Promise();
        $promise2->reject(new Exception('fail2'));

        $promises = [$promise1, $promise2];

        $promise = $handler->any($promises);

        expect(fn () => waitForPromise($promise))
            ->toThrow(AggregateErrorException::class, 'All promises were rejected')
        ;
    });

    it('validates timeout parameter', function () {
        $handler = promiseCollectionHandler();
        $promise = new Promise();

        expect(fn () => $handler->timeout($promise, 0))
            ->toThrow(InvalidArgumentException::class, 'Timeout must be greater than zero')
        ;

        expect(fn () => $handler->timeout($promise, -1))
            ->toThrow(InvalidArgumentException::class, 'Timeout must be greater than zero')
        ;
    });
});

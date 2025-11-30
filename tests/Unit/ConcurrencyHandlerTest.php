<?php

use Hibla\Promise\Promise;

describe('ConcurrencyHandler', function () {
    it('runs tasks concurrently', function () {
        $handler = concurrencyHandler();
        $tasks = [
            fn () => Promise::resolved('result1'),
            fn () => Promise::resolved('result2'),
            fn () => Promise::resolved('result3'),
        ];

        $promise = $handler->concurrent($tasks, 2);
        $results = waitForPromise($promise);

        expect($results)->toBe(['result1', 'result2', 'result3']);
    });

    it('respects concurrency limit', function () {
        $handler = concurrencyHandler();
        $counter = 0;
        $maxConcurrent = 0;

        $tasks = array_fill(0, 5, function () use (&$counter, &$maxConcurrent) {
            $counter++;
            $maxConcurrent = max($maxConcurrent, $counter);

            return new Promise(function ($resolve) use (&$counter) {
                usleep(10000);
                $counter--;
                $resolve('done');
            });
        });

        $promise = $handler->concurrent($tasks, 2);
        waitForPromise($promise);

        expect($maxConcurrent)->toBeLessThanOrEqual(2);
    });

    it('handles empty task array', function () {
        $handler = concurrencyHandler();
        $promise = $handler->concurrent([]);
        $results = waitForPromise($promise);

        expect($results)->toBe([]);
    });

    it('preserves array keys', function () {
        $handler = concurrencyHandler();
        $tasks = [
            'task1' => fn () => Promise::resolved('result1'),
            'task2' => fn () => Promise::resolved('result2'),
        ];

        $promise = $handler->concurrent($tasks);
        $results = waitForPromise($promise);

        expect($results)->toBe([
            'task1' => 'result1',
            'task2' => 'result2',
        ]);
    });

    it('handles task exceptions', function () {
        $handler = concurrencyHandler();
        $tasks = [
            fn () => Promise::resolved('success'),
            fn () => Promise::rejected(new Exception('task failed')),
        ];

        $promise = $handler->concurrent($tasks);

        expect(fn () => waitForPromise($promise))
            ->toThrow(Exception::class, 'task failed')
        ;
    });

    it('runs batch processing', function () {
        $handler = concurrencyHandler();
        $tasks = array_fill(0, 5, fn () => Promise::resolved('result'));

        $promise = $handler->batch($tasks, 2);
        $results = waitForPromise($promise);

        expect($results)->toHaveCount(5);
        expect(array_unique($results))->toBe(['result']);
    });

    it('handles concurrent settled operations', function () {
        $handler = concurrencyHandler();
        $tasks = [
            fn () => Promise::resolved('success'),
            fn () => Promise::rejected(new Exception('failure')),
            fn () => Promise::resolved('another success'),
        ];

        $promise = $handler->concurrentSettled($tasks);
        $results = waitForPromise($promise);

        expect($results)->toHaveCount(3);
        expect($results[0]['status'])->toBe('fulfilled');
        expect($results[0]['value'])->toBe('success');
        expect($results[1]['status'])->toBe('rejected');
        expect($results[2]['status'])->toBe('fulfilled');
    });

    it('validates concurrency parameter', function () {
        $handler = concurrencyHandler();

        expect(fn () => $handler->concurrent([], 0)->await())
            ->toThrow(InvalidArgumentException::class, 'Concurrency limit must be greater than 0')
        ;

        expect(fn () => $handler->concurrent([], -1)->await())
            ->toThrow(InvalidArgumentException::class, 'Concurrency limit must be greater than 0')
        ;
    });
});

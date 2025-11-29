<?php

use Hibla\Async\AsyncOperations;
use Hibla\Async\Exceptions\AggregateErrorException;
use Hibla\Async\Exceptions\TimeoutException;
use Hibla\Promise\Promise;

beforeEach(function () {
    resetEventLoop();
});

afterEach(function () {
    resetEventLoop();
});

describe('Error Handling', function () {
    it('handles synchronous errors in async functions', function () {
        $async = new AsyncOperations();
        $error = new RuntimeException('Test error');

        $asyncFunction = $async->async(function () use ($error) {
            throw $error;
        });

        $promise = $asyncFunction();

        expect($promise)->toBePromise();

        expect(fn () => waitForPromise($promise))
            ->toThrow(RuntimeException::class, 'Test error')
        ;
    });

    it('handles errors in awaited promises', function () {
        $async = new AsyncOperations();

        $asyncFunction = $async->async(function () use ($async) {
            $promise = new Promise(function ($resolve, $reject) {
                $reject(new InvalidArgumentException('Async error'));
            });

            return $async->await($promise);
        });

        expect(fn () => waitForPromise($asyncFunction()))
            ->toThrow(InvalidArgumentException::class, 'Async error')
        ;
    });

    it('handles errors in concurrent execution', function () {
        $async = new AsyncOperations();

        $tasks = [
            'success' => fn () => 'success',
            'error' => function () {
                throw new RuntimeException('Concurrent error');
            },
            'another_success' => fn () => 'another success',
        ];

        $promise = $async->concurrent($tasks);

        expect(fn () => waitForPromise($promise))
            ->toThrow(RuntimeException::class, 'Concurrent error')
        ;
    });

    it('handles errors gracefully with concurrentSettled', function () {
        $async = new AsyncOperations();

        $tasks = [
            'success' => fn () => 'success result',
            'error' => function () {
                throw new RuntimeException('Task error');
            },
            'another_success' => fn () => 'another result',
        ];

        $promise = $async->concurrentSettled($tasks);
        $results = waitForPromise($promise);

        expect($results)->toHaveKey('success');
        expect($results['success'])->toEqual([
            'status' => 'fulfilled',
            'value' => 'success result',
        ]);

        expect($results)->toHaveKey('error');
        expect($results['error']['status'])->toBe('rejected');
        expect($results['error']['reason'])->toBeInstanceOf(RuntimeException::class);
        expect($results['error']['reason']->getMessage())->toBe('Task error');

        expect($results)->toHaveKey('another_success');
        expect($results['another_success'])->toEqual([
            'status' => 'fulfilled',
            'value' => 'another result',
        ]);
    });

    it('handles errors in batch execution', function () {
        $async = new AsyncOperations();

        $tasks = [];
        for ($i = 0; $i < 5; $i++) {
            $tasks[] = $i === 2
                ? function () {
                    throw new RuntimeException('Batch error');
                }
            : fn () => "result $i";
        }

        $promise = $async->batch($tasks, 2);

        expect(fn () => waitForPromise($promise))
            ->toThrow(RuntimeException::class, 'Batch error')
        ;
    });

    it('handles errors gracefully with batchSettled', function () {
        $async = new AsyncOperations();

        $tasks = [];
        for ($i = 0; $i < 5; $i++) {
            $tasks[] = $i === 2
                ? function () use ($i) {
                    throw new RuntimeException("Error $i");
                }
            : fn () => "result $i";
        }

        $promise = $async->batchSettled($tasks, 2);
        $results = waitForPromise($promise);

        expect($results)->toHaveCount(5);

        // Check successful results
        foreach ([0, 1, 3, 4] as $index) {
            expect($results[$index]['status'])->toBe('fulfilled');
            expect($results[$index]['value'])->toBe("result $index");
        }

        // Check error result
        expect($results[2]['status'])->toBe('rejected');
        expect($results[2]['reason'])->toBeInstanceOf(RuntimeException::class);
        expect($results[2]['reason']->getMessage())->toBe('Error 2');
    });

    it('handles timeout errors', function () {
        $async = new AsyncOperations();

        $slowTask = $async->async(function () use ($async) {
            return $async->await($async->delay(2.0)); // 2 second delay
        });

        $promise = $async->timeout($slowTask(), 0.1); // 100ms timeout

        expect(fn () => waitForPromise($promise))
            ->toThrow(TimeoutException::class)
        ;
    });

    it('handles mixed success and error scenarios in concurrent operations', function () {
        $async = new AsyncOperations();

        $tasks = [
            'fast_success' => fn () => 'fast',
            'slow_success' => function () use ($async) {
                return $async->await($async->delay(0.1)->then(fn () => 'slow'));
            },
            'fast_error' => function () {
                throw new RuntimeException('Fast error');
            },
        ];

        // Test that concurrent fails fast on first error
        expect(fn () => waitForPromise($async->concurrent($tasks)))
            ->toThrow(RuntimeException::class, 'Fast error')
        ;

        // Test that concurrentSettled waits for all
        $settledResults = waitForPromise($async->concurrentSettled($tasks));
        expect($settledResults)->toHaveKey('fast_success');
        expect($settledResults)->toHaveKey('slow_success');
        expect($settledResults)->toHaveKey('fast_error');

        expect($settledResults['fast_success']['status'])->toBe('fulfilled');
        expect($settledResults['slow_success']['status'])->toBe('fulfilled');
        expect($settledResults['fast_error']['status'])->toBe('rejected');
    });

    it('handles errors with proper cleanup in cancellable operations', function () {
        $async = new AsyncOperations();

        $longRunningTask = $async->delay(5.0)->then(fn () => 'should not complete');

        $fastError = Promise::rejected(new RuntimeException('Fast error'));

        // Race should cancel the long-running task when error occurs
        $racePromise = $async->race([$longRunningTask, $fastError]);

        expect(fn () => waitForPromise($racePromise))
            ->toThrow(RuntimeException::class, 'Fast error')
        ;
    });

    it('propagates errors through nested async operations', function () {
        $async = new AsyncOperations();

        $nestedAsyncFunction = $async->async(function () use ($async) {
            $innerPromise = $async->async(function () {
                throw new RuntimeException('Nested error');
            });

            return $async->await($innerPromise());
        });

        expect(fn () => waitForPromise($nestedAsyncFunction()))
            ->toThrow(RuntimeException::class, 'Nested error')
        ;
    });
});

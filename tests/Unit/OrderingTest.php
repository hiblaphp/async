<?php

use Hibla\Promise\Exceptions\PromiseRejectionException;

describe('Array Ordering and Key Preservation', function () {

    describe('ConcurrencyHandler', function () {
        it('preserves order for indexed arrays in concurrent execution', function () {
            resetEventLoop();
            $handler = concurrencyHandler();
            $tasks = [
                delayedValue('first', 30),
                delayedValue('second', 10),
                delayedValue('third', 20),
            ];

            $results = waitForPromise($handler->concurrent($tasks, 3));

            expect($results)->toBe(['first', 'second', 'third']);
            expect(array_keys($results))->toBe([0, 1, 2]);
        });

        it('preserves keys for associative arrays in concurrent execution', function () {
            resetEventLoop();
            $handler = concurrencyHandler();
            $tasks = [
                'task_a' => delayedValue('result_a', 30),
                'task_b' => delayedValue('result_b', 10),
                'task_c' => delayedValue('result_c', 20),
            ];

            $results = waitForPromise($handler->concurrent($tasks, 3));

            expect($results)->toBe([
                'task_a' => 'result_a',
                'task_b' => 'result_b',
                'task_c' => 'result_c',
            ]);
            expect(array_keys($results))->toBe(['task_a', 'task_b', 'task_c']);
        });

        it('preserves numeric keys for non-sequential arrays', function () {
            resetEventLoop();
            $handler = concurrencyHandler();
            $tasks = [
                5 => delayedValue('fifth', 30),
                10 => delayedValue('tenth', 10),
                15 => delayedValue('fifteenth', 20),
            ];

            $results = waitForPromise($handler->concurrent($tasks, 3));

            expect($results)->toBe([
                5 => 'fifth',
                10 => 'tenth',
                15 => 'fifteenth',
            ]);
            expect(array_keys($results))->toBe([5, 10, 15]);
        });

        it('preserves order with mixed completion times and low concurrency', function () {
            resetEventLoop();
            $handler = concurrencyHandler();
            $tasks = [
                delayedValue('slow', 50),
                delayedValue('fast', 5),
                delayedValue('medium', 25),
                delayedValue('very_fast', 1),
                delayedValue('very_slow', 100),
            ];

            $results = waitForPromise($handler->concurrent($tasks, 2));

            expect($results)->toBe(['slow', 'fast', 'medium', 'very_fast', 'very_slow']);
        });

        it('preserves order in batch execution with indexed arrays', function () {
            resetEventLoop();
            $handler = concurrencyHandler();
            $tasks = [
                delayedValue('batch1_item1', 20),
                delayedValue('batch1_item2', 10),
                delayedValue('batch2_item1', 30),
                delayedValue('batch2_item2', 5),
            ];

            $results = waitForPromise($handler->batch($tasks, 2, 2));

            expect($results)->toBe(['batch1_item1', 'batch1_item2', 'batch2_item1', 'batch2_item2']);
        });

        it('preserves keys in batch execution with associative arrays', function () {
            resetEventLoop();
            $handler = concurrencyHandler();
            $tasks = [
                'user_1' => delayedValue(['id' => 1, 'name' => 'Alice'], 20),
                'user_2' => delayedValue(['id' => 2, 'name' => 'Bob'], 10),
                'user_3' => delayedValue(['id' => 3, 'name' => 'Charlie'], 30),
                'user_4' => delayedValue(['id' => 4, 'name' => 'Diana'], 5),
            ];

            $results = waitForPromise($handler->batch($tasks, 2, 2));

            expect($results)->toBe([
                'user_1' => ['id' => 1, 'name' => 'Alice'],
                'user_2' => ['id' => 2, 'name' => 'Bob'],
                'user_3' => ['id' => 3, 'name' => 'Charlie'],
                'user_4' => ['id' => 4, 'name' => 'Diana'],
            ]);
            expect(array_keys($results))->toBe(['user_1', 'user_2', 'user_3', 'user_4']);
        });

        it('preserves numeric keys in batch execution with non-sequential arrays', function () {
            resetEventLoop();
            $handler = concurrencyHandler();
            $tasks = [
                100 => delayedValue('hundred', 20),
                200 => delayedValue('two_hundred', 10),
                300 => delayedValue('three_hundred', 30),
                400 => delayedValue('four_hundred', 5),
            ];

            $results = waitForPromise($handler->batch($tasks, 2, 2));

            expect($results)->toBe([
                100 => 'hundred',
                200 => 'two_hundred',
                300 => 'three_hundred',
                400 => 'four_hundred',
            ]);
            expect(array_keys($results))->toBe([100, 200, 300, 400]);
        });

        it('preserves order in concurrentSettled with mixed success/failure', function () {
            resetEventLoop();
            $handler = concurrencyHandler();
            $tasks = [
                delayedValue('success_1', 30),
                delayedReject('error_1', 10),
                delayedValue('success_2', 20),
                delayedReject('error_2', 5),
            ];

            $results = waitForPromise($handler->concurrentSettled($tasks, 4));

            expect($results[0])->toBe(['status' => 'fulfilled', 'value' => 'success_1']);
            expect($results[1]['status'])->toBe('rejected');
            expect($results[1]['reason'])->toBeInstanceOf(PromiseRejectionException::class);
            expect($results[1]['reason']->getMessage())->toBe('error_1');
            expect($results[2])->toBe(['status' => 'fulfilled', 'value' => 'success_2']);
            expect($results[3]['status'])->toBe('rejected');
            expect($results[3]['reason'])->toBeInstanceOf(PromiseRejectionException::class);
            expect($results[3]['reason']->getMessage())->toBe('error_2');
        });

        it('preserves keys in concurrentSettled with associative arrays', function () {
            resetEventLoop();
            $handler = concurrencyHandler();
            $tasks = [
                'api_call_1' => delayedValue('response_1', 25),
                'api_call_2' => delayedReject('timeout', 15),
                'api_call_3' => delayedValue('response_3', 35),
            ];

            $results = waitForPromise($handler->concurrentSettled($tasks, 3));

            expect(array_keys($results))->toBe(['api_call_1', 'api_call_2', 'api_call_3']);
            expect($results['api_call_1']['status'])->toBe('fulfilled');
            expect($results['api_call_2']['status'])->toBe('rejected');
            expect($results['api_call_3']['status'])->toBe('fulfilled');
        });

        it('preserves numeric keys in concurrentSettled with non-sequential arrays', function () {
            resetEventLoop();
            $handler = concurrencyHandler();
            $tasks = [
                7 => delayedValue('lucky_seven', 25),
                13 => delayedReject('unlucky_thirteen', 15),
                21 => delayedValue('twenty_one', 35),
            ];

            $results = waitForPromise($handler->concurrentSettled($tasks, 3));

            expect(array_keys($results))->toBe([7, 13, 21]);
            expect($results[7]['status'])->toBe('fulfilled');
            expect($results[7]['value'])->toBe('lucky_seven');
            expect($results[13]['status'])->toBe('rejected');
            expect($results[21]['status'])->toBe('fulfilled');
            expect($results[21]['value'])->toBe('twenty_one');
        });

        it('handles empty arrays correctly', function () {
            resetEventLoop();
            $handler = concurrencyHandler();

            $results = waitForPromise($handler->concurrent([], 5));
            expect($results)->toBe([]);

            $results = waitForPromise($handler->batch([], 5));
            expect($results)->toBe([]);

            $results = waitForPromise($handler->concurrentSettled([], 5));
            expect($results)->toBe([]);
        });

        it('handles single item arrays correctly', function () {
            resetEventLoop();
            $handler = concurrencyHandler();
            $tasks = ['single' => delayedValue('result', 10)];
            $results = waitForPromise($handler->concurrent($tasks, 1));

            expect($results)->toBe(['single' => 'result']);
        });

        it('handles single item with numeric key correctly', function () {
            resetEventLoop();
            $handler = concurrencyHandler();
            $tasks = [42 => delayedValue('answer', 10)];
            $results = waitForPromise($handler->concurrent($tasks, 1));

            expect($results)->toBe([42 => 'answer']);
            expect(array_keys($results))->toBe([42]);
        });

        it('preserves order with Promise instances as tasks', function () {
            resetEventLoop();
            $handler = concurrencyHandler();
            $tasks = [
                delayedValue('promise_1', 30),
                delayedValue('promise_2', 10),
                delayedValue('promise_3', 20),
            ];

            $results = waitForPromise($handler->concurrent($tasks, 3));

            expect($results)->toBe(['promise_1', 'promise_2', 'promise_3']);
        });

        it('preserves numeric keys with Promise instances as tasks', function () {
            resetEventLoop();
            $handler = concurrencyHandler();
            $tasks = [
                25 => delayedValue('quarter', 30),
                50 => delayedValue('half', 10),
                75 => delayedValue('three_quarters', 20),
            ];

            $results = waitForPromise($handler->concurrent($tasks, 3));

            expect($results)->toBe([
                25 => 'quarter',
                50 => 'half',
                75 => 'three_quarters',
            ]);
            expect(array_keys($results))->toBe([25, 50, 75]);
        });
    });

    describe('PromiseCollectionHandler', function () {
        it('preserves order in all() with indexed arrays', function () {
            resetEventLoop();
            $handler = promiseCollectionHandler();
            $promises = [
                delayedValue('first', 30),
                delayedValue('second', 10),
                delayedValue('third', 20),
            ];

            $results = waitForPromise($handler->all($promises));

            expect($results)->toBe(['first', 'second', 'third']);
        });

        it('preserves keys in all() with associative arrays', function () {
            resetEventLoop();
            $handler = promiseCollectionHandler();
            $promises = [
                'key_a' => delayedValue('value_a', 25),
                'key_b' => delayedValue('value_b', 15),
                'key_c' => delayedValue('value_c', 35),
            ];

            $results = waitForPromise($handler->all($promises));

            expect($results)->toBe([
                'key_a' => 'value_a',
                'key_b' => 'value_b',
                'key_c' => 'value_c',
            ]);
        });

        it('preserves numeric keys in all() with non-sequential arrays', function () {
            resetEventLoop();
            $handler = promiseCollectionHandler();
            $promises = [
                5 => delayedValue('fifth', 25),
                10 => delayedValue('tenth', 15),
                15 => delayedValue('fifteenth', 35),
            ];

            $results = waitForPromise($handler->all($promises));

            expect($results)->toBe([
                5 => 'fifth',
                10 => 'tenth',
                15 => 'fifteenth',
            ]);
            expect(array_keys($results))->toBe([5, 10, 15]);
        });

        it('converts sequential numeric keys starting from 0 to indexed array', function () {
            resetEventLoop();
            $handler = promiseCollectionHandler();
            $promises = [
                0 => delayedValue('zero', 25),
                1 => delayedValue('one', 15),
                2 => delayedValue('two', 35),
            ];

            $results = waitForPromise($handler->all($promises));

            expect($results)->toBe(['zero', 'one', 'two']);
            expect(array_keys($results))->toBe([0, 1, 2]);
        });

        it('preserves non-zero starting sequential keys', function () {
            resetEventLoop();
            $handler = promiseCollectionHandler();
            $promises = [
                1 => delayedValue('one', 25),
                2 => delayedValue('two', 15),
                3 => delayedValue('three', 35),
            ];

            $results = waitForPromise($handler->all($promises));

            expect($results)->toBe([
                1 => 'one',
                2 => 'two',
                3 => 'three',
            ]);
            expect(array_keys($results))->toBe([1, 2, 3]);
        });

        it('preserves order in allSettled() with indexed arrays', function () {
            resetEventLoop();
            $handler = promiseCollectionHandler();
            $promises = [
                delayedValue('success_1', 20),
                delayedReject('error_1', 10),
                delayedValue('success_2', 30),
            ];

            $results = waitForPromise($handler->allSettled($promises));

            expect($results[0]['status'])->toBe('fulfilled');
            expect($results[0]['value'])->toBe('success_1');
            expect($results[1]['status'])->toBe('rejected');
            expect($results[2]['status'])->toBe('fulfilled');
            expect($results[2]['value'])->toBe('success_2');
        });

        it('preserves keys in allSettled() with associative arrays', function () {
            resetEventLoop();
            $handler = promiseCollectionHandler();
            $promises = [
                'operation_1' => delayedValue('done_1', 15),
                'operation_2' => delayedReject('failed', 25),
                'operation_3' => delayedValue('done_3', 5),
            ];

            $results = waitForPromise($handler->allSettled($promises));

            expect(array_keys($results))->toBe(['operation_1', 'operation_2', 'operation_3']);
            expect($results['operation_1']['status'])->toBe('fulfilled');
            expect($results['operation_2']['status'])->toBe('rejected');
            expect($results['operation_3']['status'])->toBe('fulfilled');
        });

        it('preserves numeric keys in allSettled() with non-sequential arrays', function () {
            resetEventLoop();
            $handler = promiseCollectionHandler();
            $promises = [
                3 => delayedValue('third', 15),
                6 => delayedReject('sixth_failed', 25),
                9 => delayedValue('ninth', 5),
            ];

            $results = waitForPromise($handler->allSettled($promises));

            expect(array_keys($results))->toBe([3, 6, 9]);
            expect($results[3]['status'])->toBe('fulfilled');
            expect($results[3]['value'])->toBe('third');
            expect($results[6]['status'])->toBe('rejected');
            expect($results[9]['status'])->toBe('fulfilled');
            expect($results[9]['value'])->toBe('ninth');
        });

        it('handles gaps in numeric keys correctly', function () {
            resetEventLoop();
            $handler = promiseCollectionHandler();
            $promises = [
                0 => delayedValue('zero', 15),
                2 => delayedValue('two', 25),
                4 => delayedValue('four', 5),
            ];

            $results = waitForPromise($handler->all($promises));

            expect($results)->toBe([
                0 => 'zero',
                2 => 'two',
                4 => 'four',
            ]);
            expect(array_keys($results))->toBe([0, 2, 4]);
        });

        it('handles negative numeric keys correctly', function () {
            resetEventLoop();
            $handler = promiseCollectionHandler();
            $promises = [
                -1 => delayedValue('negative_one', 15),
                0 => delayedValue('zero', 25),
                1 => delayedValue('positive_one', 5),
            ];

            $results = waitForPromise($handler->all($promises));

            expect($results)->toBe([
                -1 => 'negative_one',
                0 => 'zero',
                1 => 'positive_one',
            ]);
            expect(array_keys($results))->toBe([-1, 0, 1]);
        });
    });

    describe('Complex Scenarios', function () {
        it('maintains order with numeric string keys', function () {
            resetEventLoop();
            $handlers = complexScenarioHandlers();
            $concurrencyHandler = $handlers['concurrencyHandler'];

            $tasks = [
                '0' => delayedValue('zero', 20),
                '1' => delayedValue('one', 10),
                '2' => delayedValue('two', 30),
            ];

            $results = waitForPromise($concurrencyHandler->concurrent($tasks, 3));

            expect(array_keys($results))->toBe([0, 1, 2]);
            expect($results)->toBe([0 => 'zero', 1 => 'one', 2 => 'two']);
        });

        it('handles mixed key types correctly', function () {
            resetEventLoop();
            $handlers = complexScenarioHandlers();
            $concurrencyHandler = $handlers['concurrencyHandler'];

            $tasks = [
                0 => delayedValue('numeric_zero', 20),
                'string_key' => delayedValue('string_value', 10),
                5 => delayedValue('numeric_five', 30),
                'another' => delayedValue('another_string', 15),
            ];

            $results = waitForPromise($concurrencyHandler->concurrent($tasks, 4));

            expect(array_keys($results))->toBe([0, 'string_key', 5, 'another']);
            expect($results[0])->toBe('numeric_zero');
            expect($results['string_key'])->toBe('string_value');
            expect($results[5])->toBe('numeric_five');
            expect($results['another'])->toBe('another_string');
        });

        it('maintains order with large numeric keys', function () {
            resetEventLoop();
            $handlers = complexScenarioHandlers();
            $collectionHandler = $handlers['collectionHandler'];

            $tasks = [
                1000 => delayedValue('thousand', 30),
                2000 => delayedValue('two_thousand', 10),
                3000 => delayedValue('three_thousand', 20),
            ];

            $results = waitForPromise($collectionHandler->all($tasks));

            expect($results)->toBe([
                1000 => 'thousand',
                2000 => 'two_thousand',
                3000 => 'three_thousand',
            ]);
            expect(array_keys($results))->toBe([1000, 2000, 3000]);
        });

        it('maintains order with large arrays', function () {
            resetEventLoop();
            $handlers = complexScenarioHandlers();
            $concurrencyHandler = $handlers['concurrencyHandler'];

            $tasks = [];
            $expected = [];

            for ($i = 0; $i < 50; $i++) {
                $value = "item_{$i}";
                $delay = 50 - $i;
                $tasks[] = delayedValue($value, $delay);
                $expected[] = $value;
            }

            $results = waitForPromise($concurrencyHandler->concurrent($tasks, 10));

            expect($results)->toBe($expected);
        });

        it('handles mixed data types in results while preserving order', function () {
            resetEventLoop();
            $handlers = complexScenarioHandlers();
            $concurrencyHandler = $handlers['concurrencyHandler'];

            $tasks = [
                delayedValue(['array' => 'data'], 20),
                delayedValue(42, 10),
                delayedValue('string', 30),
                delayedValue(true, 5),
                delayedValue(null, 15),
            ];

            $results = waitForPromise($concurrencyHandler->concurrent($tasks, 5));

            expect($results[0])->toBe(['array' => 'data']);
            expect($results[1])->toBe(42);
            expect($results[2])->toBe('string');
            expect($results[3])->toBe(true);
            expect($results[4])->toBe(null);
        });

        it('preserves order with non-sequential numeric keys and mixed completion times', function () {
            resetEventLoop();
            $handlers = complexScenarioHandlers();
            $collectionHandler = $handlers['collectionHandler'];

            $promises = [
                10 => delayedValue('ten', 50),
                5 => delayedValue('five', 10),
                15 => delayedValue('fifteen', 30),
                1 => delayedValue('one', 20),
            ];

            $results = waitForPromise($collectionHandler->all($promises));

            expect($results)->toBe([
                10 => 'ten',
                5 => 'five',
                15 => 'fifteen',
                1 => 'one',
            ]);
            expect(array_keys($results))->toBe([10, 5, 15, 1]);
        });

        it('distinguishes between sequential and non-sequential numeric arrays', function () {
            resetEventLoop();
            $handlers = complexScenarioHandlers();
            $collectionHandler = $handlers['collectionHandler'];

            $sequential = [
                0 => delayedValue('zero', 10),
                1 => delayedValue('one', 20),
                2 => delayedValue('two', 15),
            ];

            $sequentialResults = waitForPromise($collectionHandler->all($sequential));
            expect($sequentialResults)->toBe(['zero', 'one', 'two']);
            expect(array_keys($sequentialResults))->toBe([0, 1, 2]);

            $nonSequential = [
                0 => delayedValue('zero', 10),
                1 => delayedValue('one', 20),
                3 => delayedValue('three', 15),
            ];

            $nonSequentialResults = waitForPromise($collectionHandler->all($nonSequential));
            expect($nonSequentialResults)->toBe([
                0 => 'zero',
                1 => 'one',
                3 => 'three',
            ]);
            expect(array_keys($nonSequentialResults))->toBe([0, 1, 3]);
        });
    });
});

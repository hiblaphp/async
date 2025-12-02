<?php

use Hibla\Promise\Promise;
use function Hibla\async;
use function Hibla\asyncFn;
use function Hibla\await;
use function Hibla\sleep;

describe('Async Suspension and Concurrency', function () {

    it('executes async operations concurrently without blocking', function () {
        $startTime = microtime(true);
        $results = [];

        $promise1 = async(function () use (&$results) {
            $results[] = 'Task 1 started';
            sleep(1.0);
            $results[] = 'Task 1 completed';
            return 'Result 1';
        });

        $promise2 = async(function () use (&$results) {
            $results[] = 'Task 2 started';
            sleep(1.0);
            $results[] = 'Task 2 completed';
            return 'Result 2';
        });

        $promise3 = async(function () use (&$results) {
            $results[] = 'Task 3 started';
            sleep(1.0);
            $results[] = 'Task 3 completed';
            return 'Result 3';
        });

        $allResults = await(Promise::all([
            'first' => $promise1,
            'second' => $promise2,
            'third' => $promise3,
        ]));

        $endTime = microtime(true);
        $duration = $endTime - $startTime;

        expect($duration)->toBeLessThan(1.5)
            ->and($duration)->toBeGreaterThan(0.9);

        expect($allResults)->toBe([
            'first' => 'Result 1',
            'second' => 'Result 2',
            'third' => 'Result 3',
        ]);

        expect($results)->toContain('Task 1 started')
            ->and($results)->toContain('Task 2 started')
            ->and($results)->toContain('Task 3 started')
            ->and($results)->toContain('Task 1 completed')
            ->and($results)->toContain('Task 2 completed')
            ->and($results)->toContain('Task 3 completed');
    });

    it('suspends fiber execution and allows other fibers to run', function () {
        $executionOrder = [];

        $promise1 = async(function () use (&$executionOrder) {
            $executionOrder[] = 'A1';
            sleep(0.2);
            $executionOrder[] = 'A2';
            sleep(0.2);
            $executionOrder[] = 'A3';
            return 'A';
        });

        $promise2 = async(function () use (&$executionOrder) {
            $executionOrder[] = 'B1';
            sleep(0.15);
            $executionOrder[] = 'B2';
            sleep(0.15);
            $executionOrder[] = 'B3';
            return 'B';
        });

        $results = await(Promise::all([$promise1, $promise2]));

        expect($results)->toBe(['A', 'B']);

        $firstAIndex = array_search('A1', $executionOrder);
        $firstBIndex = array_search('B1', $executionOrder);
        $secondAIndex = array_search('A2', $executionOrder);
        $secondBIndex = array_search('B2', $executionOrder);

        expect($executionOrder)->toHaveCount(6)
            ->and($firstAIndex)->not->toBeFalse()
            ->and($firstBIndex)->not->toBeFalse()
            ->and($secondAIndex)->not->toBeFalse()
            ->and($secondBIndex)->not->toBeFalse();

        $aIndices = [
            array_search('A1', $executionOrder),
            array_search('A2', $executionOrder),
            array_search('A3', $executionOrder),
        ];
        $bIndices = [
            array_search('B1', $executionOrder),
            array_search('B2', $executionOrder),
            array_search('B3', $executionOrder),
        ];

        $isInterleaved = false;
        foreach ($bIndices as $bIndex) {
            if ($bIndex > $aIndices[0] && $bIndex < $aIndices[2]) {
                $isInterleaved = true;
                break;
            }
        }

        expect($isInterleaved)->toBeTrue();
    });

    it('demonstrates blocking behavior would take 3x longer', function () {
        $concurrentStart = microtime(true);

        $promises = [
            async(fn() => sleep(0.5)),
            async(fn() => sleep(0.5)),
            async(fn() => sleep(0.5)),
        ];

        await(Promise::all($promises));
        $concurrentDuration = microtime(true) - $concurrentStart;

        $sequentialStart = microtime(true);
        sleep(0.5);
        sleep(0.5);
        sleep(0.5);
        $sequentialDuration = microtime(true) - $sequentialStart;

        expect($concurrentDuration)->toBeLessThan(0.8)
            ->and($sequentialDuration)->toBeGreaterThan(1.4)
            ->and($sequentialDuration)->toBeGreaterThan($concurrentDuration * 2.5);
    });

    it('handles nested async operations with proper suspension', function () {
        $timeline = [];

        $promise = async(function () use (&$timeline) {
            $timeline[] = ['event' => 'outer_start', 'time' => microtime(true)];

            $innerPromise = async(function () use (&$timeline) {
                $timeline[] = ['event' => 'inner_start', 'time' => microtime(true)];
                sleep(0.3);
                $timeline[] = ['event' => 'inner_end', 'time' => microtime(true)];
                return 'inner_result';
            });

            $timeline[] = ['event' => 'before_await', 'time' => microtime(true)];
            $innerResult = await($innerPromise);
            $timeline[] = ['event' => 'after_await', 'time' => microtime(true)];

            sleep(0.2);
            $timeline[] = ['event' => 'outer_end', 'time' => microtime(true)];

            return $innerResult . '_outer';
        });

        $result = await($promise);

        expect($result)->toBe('inner_result_outer')
            ->and($timeline)->toHaveCount(6);

        $startTime = $timeline[0]['time'];
        $endTime = $timeline[5]['time'];
        $totalDuration = $endTime - $startTime;

        expect($totalDuration)->toBeGreaterThan(0.4)
            ->and($totalDuration)->toBeLessThan(0.7);
    });

    it('allows other tasks to execute during await suspension', function () {
        $executionLog = [];
        $startTime = microtime(true);

        $longTask = async(function () use (&$executionLog, $startTime) {
            $executionLog[] = ['task' => 'long', 'event' => 'start', 'offset' => microtime(true) - $startTime];
            sleep(1.0);
            $executionLog[] = ['task' => 'long', 'event' => 'end', 'offset' => microtime(true) - $startTime];
            return 'long_done';
        });

        $quickTask1 = async(function () use (&$executionLog, $startTime) {
            $executionLog[] = ['task' => 'quick1', 'event' => 'start', 'offset' => microtime(true) - $startTime];
            sleep(0.2);
            $executionLog[] = ['task' => 'quick1', 'event' => 'end', 'offset' => microtime(true) - $startTime];
            return 'quick1_done';
        });

        $quickTask2 = async(function () use (&$executionLog, $startTime) {
            $executionLog[] = ['task' => 'quick2', 'event' => 'start', 'offset' => microtime(true) - $startTime];
            sleep(0.3);
            $executionLog[] = ['task' => 'quick2', 'event' => 'end', 'offset' => microtime(true) - $startTime];
            return 'quick2_done';
        });

        $results = await(Promise::all([
            'long' => $longTask,
            'quick1' => $quickTask1,
            'quick2' => $quickTask2,
        ]));

        expect($results)->toBe([
            'long' => 'long_done',
            'quick1' => 'quick1_done',
            'quick2' => 'quick2_done',
        ]);

        $tasks = array_column($executionLog, 'task');
        expect($tasks)->toContain('long')
            ->and($tasks)->toContain('quick1')
            ->and($tasks)->toContain('quick2');

        $quick1EndIndex = array_search(['task' => 'quick1', 'event' => 'end'], array_map(
            fn($log) => ['task' => $log['task'], 'event' => $log['event']],
            $executionLog
        ));
        $longEndIndex = array_search(['task' => 'long', 'event' => 'end'], array_map(
            fn($log) => ['task' => $log['task'], 'event' => $log['event']],
            $executionLog
        ));

        expect($quick1EndIndex)->toBeLessThan($longEndIndex);
    });

    it('measures accurate timing for concurrent operations', function () {
        $timings = [];

        $createTimedTask = asyncFn(function (string $name, float $duration) use (&$timings) {
            $start = microtime(true);
            sleep($duration);
            $end = microtime(true);

            $timings[$name] = [
                'duration' => $end - $start,
                'expected' => $duration,
            ];

            return $name;
        });

        $overallStart = microtime(true);

        $results = await(Promise::all([
            $createTimedTask('task_500ms', 0.5),
            $createTimedTask('task_300ms', 0.3),
            $createTimedTask('task_700ms', 0.7),
        ]));

        $overallDuration = microtime(true) - $overallStart;

        expect($overallDuration)->toBeGreaterThan(0.65)
            ->and($overallDuration)->toBeLessThan(0.85);

        expect($timings['task_500ms']['duration'])->toBeGreaterThan(0.45)
            ->and($timings['task_500ms']['duration'])->toBeLessThan(0.65);

        expect($timings['task_300ms']['duration'])->toBeGreaterThan(0.25)
            ->and($timings['task_300ms']['duration'])->toBeLessThan(0.45);

        expect($timings['task_700ms']['duration'])->toBeGreaterThan(0.65)
            ->and($timings['task_700ms']['duration'])->toBeLessThan(0.85);

        expect($results)->toHaveCount(3);
    });
});

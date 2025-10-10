<?php

use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;
use function Hibla\Promise\all;
use function Hibla\Promise\concurrent;
use function Hibla\Promise\batch;


describe('Basic Mutex Functionality', function () {
    it('starts in correct initial state and handles acquire/release', function () {
        $mutex = mutex();
        
        // Test initial state
        expect($mutex->isLocked())->toBeFalse();
        expect($mutex->getQueueLength())->toBe(0);
        expect($mutex->isQueueEmpty())->toBeTrue();

        // Test acquire and release
        $lockPromise = $mutex->acquire();
        expect($mutex->isLocked())->toBeTrue();

        $acquiredMutex = await($lockPromise);
        expect($acquiredMutex)->toBe($mutex);

        $acquiredMutex->release();
        expect($mutex->isLocked())->toBeFalse();
    });
});

describe('Concurrent Access Protection', function () {
    it('protects shared resources from race conditions', function () {
        $mutex = mutex();
        $sharedCounter = 0;
        $sharedLog = [];
        $tasks = [];
        $expectedResults = [];

        for ($i = 1; $i <= 5; $i++) {
            $expectedResults[] = "Task-$i completed";
            $tasks[] = async(function() use ($mutex, &$sharedCounter, &$sharedLog, $i) {
                $lock = await($mutex->acquire());

                $oldValue = $sharedCounter;
                await(delay(0.01));
                $sharedCounter++;
                $sharedLog[] = "Task-$i: $oldValue -> {$sharedCounter}";

                $lock->release();
                return "Task-$i completed";
            });
        }

        // Wait for all tasks
        $results = [];
        foreach ($tasks as $task) {
            $results[] = await($task);
        }

        expect($sharedCounter)->toBe(5);
        expect($sharedLog)->toHaveCount(5);
        expect($results)->toBe($expectedResults);

        // Verify sequential execution
        for ($i = 0; $i < 5; $i++) {
            expect($sharedLog[$i])->toContain("$i -> " . ($i + 1));
        }
    });
});

describe('Promise::all() Integration', function () {
    it('integrates correctly with Promise::all()', function () {
        $mutex = mutex();
        $sharedCounter = 0;
        $sharedLog = [];
        $tasks = [];
        
        for ($i = 1; $i <= 4; $i++) {
            $tasks[] = async(function() use ($mutex, &$sharedCounter, &$sharedLog, $i) {
                $lock = await($mutex->acquire());
                
                $oldValue = $sharedCounter;
                await(delay(0.02));
                $sharedCounter++;
                $sharedLog[] = "AllTask-$i: $oldValue -> {$sharedCounter}";
                
                $lock->release();
                return "AllTask-$i result: {$sharedCounter}";
            });
        }

        $results = await(all($tasks));

        expect($sharedCounter)->toBe(4);
        expect($results)->toHaveCount(4);
        expect($sharedLog)->toHaveCount(4);

        foreach ($results as $i => $result) {
            expect($result)->toContain("AllTask-" . ($i + 1));
        }
    });
});

describe('Promise::concurrent() Integration', function () {
    it('works with concurrent promise execution while limiting concurrency', function () {
        $mutex = mutex();
        $sharedCounter = 0;
        $sharedLog = [];
        $tasks = [];
        
        for ($i = 1; $i <= 6; $i++) {
            $tasks[] = function() use ($mutex, &$sharedCounter, &$sharedLog, $i) {
                $lock = await($mutex->acquire());
                
                $oldValue = $sharedCounter;
                await(delay(0.01));
                $sharedCounter++;
                $sharedLog[] = "ConcTask-$i: $oldValue -> {$sharedCounter}";
                
                $lock->release();
                return "ConcTask-$i completed";
            };
        }

        $results = await(concurrent($tasks, 3)); // Limit to 3 concurrent

        expect($sharedCounter)->toBe(6);
        expect($results)->toHaveCount(6);
        expect($sharedLog)->toHaveCount(6);

        // Verify sequential access despite concurrency limit
        for ($i = 0; $i < 6; $i++) {
            expect($sharedLog[$i])->toContain("$i -> " . ($i + 1));
        }
    });
});

describe('Promise::batch() Integration', function () {
    it('processes batches correctly with mutex protection', function () {
        $mutex = mutex();
        $sharedCounter = 0;
        $sharedLog = [];
        $tasks = [];
        
        for ($i = 1; $i <= 5; $i++) {
            $tasks[] = function() use ($mutex, &$sharedCounter, &$sharedLog, $i) {
                $lock = await($mutex->acquire());
                
                $oldValue = $sharedCounter;
                await(delay(0.01));
                $sharedCounter++;
                $sharedLog[] = "BatchTask-$i: $oldValue -> {$sharedCounter}";
                
                $lock->release();
                return "BatchTask-$i done";
            };
        }

        $results = await(batch($tasks, 2, 2)); // Batches of 2, max 2 concurrent

        expect($sharedCounter)->toBe(5);
        expect($results)->toHaveCount(5);
        expect($sharedLog)->toHaveCount(5);
    });
});

describe('Multiple Mutexes', function () {
    it('allows independent protection of different shared resources', function () {
        $resource1 = 0;
        $resource2 = 0;
        $mutex1 = mutex();
        $mutex2 = mutex();

        $tasks = [];
        for ($i = 1; $i <= 3; $i++) {
            $tasks[] = async(function() use ($i, $mutex1, $mutex2, &$resource1, &$resource2) {
                // Access resource1
                $lock1 = await($mutex1->acquire());
                $resource1 += $i;
                $lock1->release();

                await(delay(0.01));

                // Access resource2
                $lock2 = await($mutex2->acquire());
                $resource2 += $i * 2;
                $lock2->release();

                return "MultiTask $i completed";
            });
        }

        $results = await(all($tasks));

        expect($resource1)->toBe(6); // 1+2+3
        expect($resource2)->toBe(12); // 2+4+6
        expect($results)->toHaveCount(3);
    });
});

describe('Mutex Queueing', function () {
    it('properly queues and processes waiting acquire requests', function () {
        $mutex = mutex();
        
        // First acquire - should succeed immediately
        $firstLock = await($mutex->acquire());
        expect($mutex->isLocked())->toBeTrue();
        expect($mutex->getQueueLength())->toBe(0);

        // Second and third acquire - should be queued
        $secondPromise = $mutex->acquire();
        $thirdPromise = $mutex->acquire();
        expect($mutex->getQueueLength())->toBe(2);

        // Release first lock - should pass to second waiter
        $firstLock->release();
        expect($mutex->isLocked())->toBeTrue(); // Still locked by second
        expect($mutex->getQueueLength())->toBe(1);

        // Get second lock and release
        $secondLock = await($secondPromise);
        expect($secondLock)->toBe($mutex);
        $secondLock->release();
        expect($mutex->getQueueLength())->toBe(0);

        // Get third lock and release
        $thirdLock = await($thirdPromise);
        $thirdLock->release();
        expect($mutex->isLocked())->toBeFalse();
        expect($mutex->isQueueEmpty())->toBeTrue();
    });
});
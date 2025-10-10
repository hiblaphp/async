<?php

use Hibla\Async\Mutex;
use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;

function mutexTestSetup(): array
{
    return [
        'mutex' => new Mutex(),
        'sharedCounter' => 0,
        'sharedLog' => [],
    ];
}

describe('Basic Mutex Operations', function () {
    it('starts in unlocked state', function () {
        $setup = mutexTestSetup();
        $mutex = $setup['mutex'];

        expect($mutex->isLocked())->toBeFalse();
        expect($mutex->getQueueLength())->toBe(0);
        expect($mutex->isQueueEmpty())->toBeTrue();
    });

    it('can acquire and release lock', function () {
        $setup = mutexTestSetup();
        $mutex = $setup['mutex'];

        $lockPromise = $mutex->acquire();
        expect($mutex->isLocked())->toBeTrue();

        $acquiredMutex = await($lockPromise);
        expect($acquiredMutex)->toBe($mutex);

        $acquiredMutex->release();
        expect($mutex->isLocked())->toBeFalse();
    });

    it('queues multiple acquire attempts', function () {
        $setup = mutexTestSetup();
        $mutex = $setup['mutex'];

        $firstLock = await($mutex->acquire());
        expect($mutex->isLocked())->toBeTrue();

        $secondLockPromise = $mutex->acquire();
        expect($mutex->getQueueLength())->toBe(1);

        $thirdLockPromise = $mutex->acquire();
        expect($mutex->getQueueLength())->toBe(2);

        $firstLock->release();
        expect($mutex->isLocked())->toBeTrue();
        expect($mutex->getQueueLength())->toBe(1);

        $secondLock = await($secondLockPromise);
        $secondLock->release();
        expect($mutex->getQueueLength())->toBe(0);

        $thirdLock = await($thirdLockPromise);
        $thirdLock->release();
        expect($mutex->isLocked())->toBeFalse();
    });
});

describe('Concurrent Access Protection', function () {
    it('protects shared resource from race conditions', function () {
        $setup = mutexTestSetup();
        $mutex = $setup['mutex'];
        $sharedCounter = &$setup['sharedCounter'];
        $sharedLog = &$setup['sharedLog'];
        
        $tasks = [];
        
        for ($i = 1; $i <= 5; $i++) {
            $tasks[] = async(function() use ($i, $mutex, &$sharedCounter, &$sharedLog) {
                $lock = await($mutex->acquire());
                
                $oldValue = $sharedCounter;
                await(delay(0.01));
                $sharedCounter++;
                $sharedLog[] = "Task-$i: $oldValue -> {$sharedCounter}";
                
                $lock->release();
                return "Task-$i completed";
            });
        }

        foreach ($tasks as $task) {
            await($task);
        }

        expect($sharedCounter)->toBe(5);
        expect(count($sharedLog))->toBe(5);
        
        foreach ($sharedLog as $index => $entry) {
            expect($entry)->toContain("$index -> " . ($index + 1));
        }
    });

    it('handles quick succession acquire/release', function () {
        $setup = mutexTestSetup();
        $mutex = $setup['mutex'];

        for ($i = 1; $i <= 10; $i++) {
            $lock = await($mutex->acquire());
            expect($mutex->isLocked())->toBeTrue();
            $lock->release();
        }

        expect($mutex->isLocked())->toBeFalse();
        expect($mutex->isQueueEmpty())->toBeTrue();
    });
});

describe('Mutex State Inspection', function () {
    it('correctly reports lock state', function () {
        $setup = mutexTestSetup();
        $mutex = $setup['mutex'];

        expect($mutex->isLocked())->toBeFalse();
        
        $lock = await($mutex->acquire());
        expect($mutex->isLocked())->toBeTrue();
        
        $lock->release();
        expect($mutex->isLocked())->toBeFalse();
    });

    it('correctly reports queue length', function () {
        $setup = mutexTestSetup();
        $mutex = $setup['mutex'];

        $firstLock = await($mutex->acquire());
        expect($mutex->getQueueLength())->toBe(0);

        $secondPromise = $mutex->acquire();
        expect($mutex->getQueueLength())->toBe(1);

        $thirdPromise = $mutex->acquire();
        expect($mutex->getQueueLength())->toBe(2);

        $firstLock->release();
        expect($mutex->getQueueLength())->toBe(1);

        $secondLock = await($secondPromise);
        $secondLock->release();
        expect($mutex->getQueueLength())->toBe(0);

        $thirdLock = await($thirdPromise);
        $thirdLock->release();
        expect($mutex->isQueueEmpty())->toBeTrue();
    });
});
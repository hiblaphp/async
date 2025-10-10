<?php

use Hibla\Async\Handlers\TimerHandler;

function timerHandler(): TimerHandler
{
    return new TimerHandler();
}

describe('TimerHandler', function () {
    it('creates delay promise', function () {
        $handler = timerHandler();
        $promise = $handler->delay(0.01); // 10ms
        
        expect($promise)->toBeCancellablePromise();
        expect($promise->isPending())->toBe(true);
    });

    it('resolves after delay', function () {
        $handler = timerHandler();
        $start = microtime(true);
        $promise = $handler->delay(0.05); // 50ms
        
        $result = waitForPromise($promise);
        $elapsed = microtime(true) - $start;
        
        expect($result)->toBeNull();
        expect($elapsed)->toBeGreaterThanOrEqual(0.04); // Allow some margin
    });

    it('can be cancelled', function () {
        $handler = timerHandler();
        $promise = $handler->delay(0.1);
        
        expect($promise->isCancelled())->toBeFalse();
        
        $promise->cancel();
        
        expect($promise->isCancelled())->toBeTrue();
    });

    it('handles fractional seconds', function () {
        $handler = timerHandler();
        $start = microtime(true);
        $promise = $handler->delay(0.001); // 1ms
        
        waitForPromise($promise);
        $elapsed = microtime(true) - $start;
        
        expect($elapsed)->toBeLessThan(0.05);
    });
});
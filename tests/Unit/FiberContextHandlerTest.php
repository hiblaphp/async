<?php

use Hibla\Async\Handlers\FiberContextHandler;

function fiberContextHandler(): FiberContextHandler
{
    return new FiberContextHandler();
}

describe('FiberContextHandler', function () {
    it('detects when not in fiber context', function () {
        $handler = fiberContextHandler();
        expect($handler->inFiber())->toBeFalse();
    });

    it('throws exception when validating outside fiber context', function () {
        $handler = fiberContextHandler();
        expect(fn () => $handler->validateFiberContext())
            ->toThrow(RuntimeException::class, 'Operation can only be used inside a Fiber context')
        ;
    });

    it('throws exception with custom message', function () {
        $handler = fiberContextHandler();
        $customMessage = 'Custom fiber context required';

        expect(fn () => $handler->validateFiberContext($customMessage))
            ->toThrow(RuntimeException::class, $customMessage)
        ;
    });

    it('detects when inside fiber context', function () {
        $result = null;

        $fiber = new Fiber(function () use (&$result) {
            $handler = new FiberContextHandler();
            $result = $handler->inFiber();
        });

        $fiber->start();

        expect($result)->toBeTrue();
    });

    it('validates successfully inside fiber context', function () {
        $validated = false;

        $fiber = new Fiber(function () use (&$validated) {
            $handler = new FiberContextHandler();
            $handler->validateFiberContext();
            $validated = true;
        });

        $fiber->start();

        expect($validated)->toBeTrue();
    });
});

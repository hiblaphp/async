<?php

declare(strict_types=1);

use function Hibla\async;
use function Hibla\asyncFn;
use function Hibla\await;
use function Hibla\inFiber;

use Hibla\Promise\Interfaces\PromiseInterface;

use function Hibla\sleep;

describe('inFiber()', function () {
    it('returns false when not in a fiber context', function () {
        expect(inFiber())->toBeFalse();
    });

    it('returns true when inside a fiber context', function () {
        $result = null;

        $fiber = new Fiber(function () use (&$result) {
            $result = inFiber();
        });

        $fiber->start();

        expect($result)->toBeTrue();
    });

    it('returns true when inside async context', function () {
        $result = null;

        $promise = async(function () use (&$result) {
            $result = inFiber();

            return true;
        });

        await($promise);

        expect($result)->toBeTrue();
    });
});

describe('async()', function () {
    it('returns a Promise', function () {
        $promise = async(fn () => 'test');

        expect($promise)->toBeInstanceOf(PromiseInterface::class);
    });

    it('executes the function and resolves with the return value', function () {
        $promise = async(fn () => 'hello world');

        $result = await($promise);

        expect($result)->toBe('hello world');
    });

    it('resolves with complex return values', function () {
        $promise = async(fn () => ['key' => 'value', 'number' => 42]);

        $result = await($promise);

        expect($result)->toBe(['key' => 'value', 'number' => 42]);
    });

    it('handles exceptions and rejects the promise', function () {
        $promise = async(function () {
            throw new Exception('Test error');
        });

        expect(fn () => await($promise))
            ->toThrow(Exception::class, 'Test error')
        ;
    });

    it('allows nested async calls', function () {
        $promise = async(function () {
            $inner = async(fn () => 'nested');

            return await($inner) . ' result';
        });

        $result = await($promise);

        expect($result)->toBe('nested result');
    });

    it('uses cached handler instance', function () {
        $promise1 = async(fn () => 1);
        $promise2 = async(fn () => 2);

        expect(await($promise1))->toBe(1);
        expect(await($promise2))->toBe(2);
    });
});

describe('asyncFn()', function () {
    it('returns a callable', function () {
        $asyncFunc = asyncFn(fn ($x) => $x * 2);

        expect($asyncFunc)->toBeCallable();
    });

    it('returns a function that produces promises', function () {
        $asyncFunc = asyncFn(fn ($x) => $x * 2);

        $promise = $asyncFunc(5);

        expect($promise)->toBeInstanceOf(PromiseInterface::class);
    });

    it('wrapped function resolves with correct values', function () {
        $asyncFunc = asyncFn(fn ($x, $y) => $x + $y);

        $result = await($asyncFunc(10, 20));

        expect($result)->toBe(30);
    });

    it('preserves function arguments', function () {
        $asyncFunc = asyncFn(function ($name, $age) {
            return "Name: $name, Age: $age";
        });

        $result = await($asyncFunc('John', 25));

        expect($result)->toBe('Name: John, Age: 25');
    });

    it('handles exceptions in wrapped function', function () {
        $asyncFunc = asyncFn(function ($value) {
            if ($value < 0) {
                throw new InvalidArgumentException('Value must be positive');
            }

            return $value;
        });

        expect(fn () => await($asyncFunc(-5)))
            ->toThrow(InvalidArgumentException::class, 'Value must be positive')
        ;
    });

    it('can be called multiple times', function () {
        $asyncFunc = asyncFn(fn ($x) => $x ** 2);

        expect(await($asyncFunc(2)))->toBe(4);
        expect(await($asyncFunc(3)))->toBe(9);
        expect(await($asyncFunc(4)))->toBe(16);
    });
});

describe('await()', function () {
    it('resolves a promise and returns its value', function () {
        $promise = async(fn () => 'resolved value');

        $result = await($promise);

        expect($result)->toBe('resolved value');
    });

    it('throws exception when promise is rejected', function () {
        $promise = async(function () {
            throw new RuntimeException('Promise rejected');
        });

        expect(fn () => await($promise))
            ->toThrow(RuntimeException::class, 'Promise rejected')
        ;
    });

    it('works outside fiber context (blocking mode)', function () {
        $promise = async(fn () => 'blocking result');

        $result = await($promise);

        expect($result)->toBe('blocking result');
        expect(inFiber())->toBeFalse();
    });

    it('works inside fiber context (non-blocking mode)', function () {
        $result = null;

        $promise = async(function () use (&$result) {
            $innerPromise = async(fn () => 'fiber result');
            $result = await($innerPromise);

            return $result;
        });

        await($promise);

        expect($result)->toBe('fiber result');
    });

    it('handles sequential awaits', function () {
        $promise = async(function () {
            $first = await(async(fn () => 'first'));
            $second = await(async(fn () => 'second'));

            return "$first-$second";
        });

        $result = await($promise);

        expect($result)->toBe('first-second');
    });

    it('uses cached handler instance', function () {
        $promise1 = async(fn () => 'test1');
        $promise2 = async(fn () => 'test2');

        // Both should work with the same cached handler
        expect(await($promise1))->toBe('test1');
        expect(await($promise2))->toBe('test2');
    });
});

describe('sleep()', function () {
    it('pauses execution for the specified duration', function () {
        $start = microtime(true);

        $promise = async(function () {
            sleep(0.1); // 100ms

            return 'done';
        });

        $result = await($promise);
        $elapsed = microtime(true) - $start;

        expect($result)->toBe('done');
        expect($elapsed)->toBeGreaterThanOrEqual(0.1);
    });

    it('works outside async context (blocking)', function () {
        $start = microtime(true);

        sleep(0.05);

        $elapsed = microtime(true) - $start;

        expect($elapsed)->toBeGreaterThanOrEqual(0.05);
    });

    it('allows concurrent execution in async context', function () {
        $results = [];
        $start = microtime(true);

        $promise1 = async(function () use (&$results) {
            $results[] = 'task1-start';
            sleep(0.2);
            $results[] = 'task1-end';
        });

        $promise2 = async(function () use (&$results) {
            $results[] = 'task2-start';
            sleep(0.1);
            $results[] = 'task2-end';
        });

        await($promise1);
        await($promise2);

        microtime(true) - $start;

        expect($results)->toContain('task2-end');
        expect($results)->toContain('task1-end');
    });

    it('supports fractional seconds', function () {
        $start = microtime(true);

        $promise = async(function () {
            sleep(0.15);
        });

        await($promise);
        $elapsed = microtime(true) - $start;

        expect($elapsed)->toBeGreaterThanOrEqual(0.15);
        expect($elapsed)->toBeLessThan(0.3);
    });
});

describe('Integration Tests', function () {
    it('handles complex async workflows', function () {
        $promise = async(function () {
            $user = await(async(fn () => ['id' => 1, 'name' => 'John']));
            sleep(0.05);
            $posts = await(async(fn () => ['post1', 'post2']));

            return [
                'user' => $user,
                'posts' => $posts,
            ];
        });

        $result = await($promise);

        expect($result)->toBe([
            'user' => ['id' => 1, 'name' => 'John'],
            'posts' => ['post1', 'post2'],
        ]);
    });

    it('handles errors in async workflows', function () {
        $promise = async(function () {
            $value = await(async(fn () => 10));

            if ($value < 20) {
                throw new Exception('Value too small');
            }

            return $value;
        });

        expect(fn () => await($promise))
            ->toThrow(Exception::class, 'Value too small')
        ;
    });

    it('supports asyncFn in workflows', function () {
        $fetchData = asyncFn(fn ($id) => "data-$id");

        $promise = async(function () use ($fetchData) {
            $data1 = await($fetchData(1));
            $data2 = await($fetchData(2));

            return [$data1, $data2];
        });

        $result = await($promise);

        expect($result)->toBe(['data-1', 'data-2']);
    });
});

<?php

use function Hibla\async;
use function Hibla\await;
use function Hibla\sleep;

describe('Error Handling', function () {
    
    it('throws exception from async function', function () {
        $promise = async(function () {
            throw new RuntimeException('Test error');
        });
        
        expect(fn() => await($promise))
            ->toThrow(RuntimeException::class, 'Test error');
    });

    it('catches exception using promise catch', function () {
        $caught = false;
        $errorMessage = '';

        $promise = async(function () {
            throw new RuntimeException('Async error');
        });

        $promise->catch(function (Throwable $e) use (&$caught, &$errorMessage) {
            $caught = true;
            $errorMessage = $e->getMessage();
        });

        try {
            await($promise);
        } catch (Throwable $e) {
            // Exception already handled by catch
        }

        expect($caught)->toBeTrue()
            ->and($errorMessage)->toBe('Async error');
    });

    it('propagates exception through nested async calls', function () {
        $promise = async(function () {
            return await(async(function () {
                throw new Exception('Nested error');
            }));
        });

        expect(fn() => await($promise))
            ->toThrow(Exception::class, 'Nested error');
    });

    it('handles exception after successful operations', function () {
        $executed = false;

        $promise = async(function () use (&$executed) {
            sleep(0.1);
            $executed = true;
            throw new RuntimeException('Error after sleep');
        });

        expect(fn() => await($promise))
            ->toThrow(RuntimeException::class, 'Error after sleep');
        
        expect($executed)->toBeTrue();
    });

    it('continues other async operations when one fails', function () {
        $result1 = null;
        $result2 = null;
        $error = null;

        $promise1 = async(function () {
            sleep(0.1);
            throw new RuntimeException('Task 1 failed');
        });

        $promise2 = async(function () {
            sleep(0.2);
            return 'Task 2 completed';
        });

        $promise1->catch(function (Throwable $e) use (&$error) {
            $error = $e->getMessage();
        });

        $promise2->then(function ($value) use (&$result2) {
            $result2 = $value;
        });

        // Await the successful promise
        $result = await($promise2);

        expect($error)->toBe('Task 1 failed')
            ->and($result2)->toBe('Task 2 completed')
            ->and($result)->toBe('Task 2 completed');
    });

    it('handles exception in promise then callback', function () {
        $caught = false;

        $promise = async(function () {
            return 'success';
        });

        $chainedPromise = $promise
            ->then(function ($value) {
                throw new RuntimeException('Error in then callback');
            })
            ->catch(function (Throwable $e) use (&$caught) {
                $caught = true;
            });

        try {
            await($chainedPromise);
        } catch (Throwable $e) {
            // Already handled
        }

        expect($caught)->toBeTrue();
    });

    it('throws exception when awaiting rejected promise', function () {
        $promise = async(function () {
            $innerPromise = async(function () {
                throw new Exception('Inner rejection');
            });

            return await($innerPromise);
        });

        expect(fn() => await($promise))
            ->toThrow(Exception::class, 'Inner rejection');
    });

    it('handles multiple sequential errors', function () {
        $errors = [];

        $task = async(function () use (&$errors) {
            try {
                await(async(function () {
                    throw new Exception('Error 1');
                }));
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }

            try {
                await(async(function () {
                    throw new Exception('Error 2');
                }));
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }

            return 'completed';
        });

        $result = await($task);

        expect($errors)->toBe(['Error 1', 'Error 2'])
            ->and($result)->toBe('completed');
    });

    it('handles exception with custom exception types', function () {
        class CustomException extends Exception {}

        $promise = async(function () {
            throw new CustomException('Custom error');
        });

        expect(fn() => await($promise))
            ->toThrow(CustomException::class, 'Custom error');
    });

    it('preserves exception stack trace', function () {
        $exception = null;

        $promise = async(function () {
            throw new RuntimeException('Stack trace test');
        });

        $promise->catch(function (Throwable $e) use (&$exception) {
            $exception = $e;
        });

        try {
            await($promise);
        } catch (Throwable $e) {
            // Already handled
        }

        expect($exception)->toBeInstanceOf(RuntimeException::class)
            ->and($exception->getTrace())->not->toBeEmpty();
    });

    it('handles error in await outside fiber context', function () {
        $promise = async(function () {
            throw new RuntimeException('Non-fiber error');
        });

        // Await outside fiber should still throw
        expect(fn() => await($promise))
            ->toThrow(RuntimeException::class, 'Non-fiber error');
    });

    it('handles promise rejection with non-exception values', function () {
        $promise = async(function () {
            // Simulate manual promise rejection with string
            return await(
                (new \Hibla\Promise\Promise(function ($resolve, $reject) {
                    $reject('String error message');
                }))
            );
        });

        expect(fn() => await($promise))
            ->toThrow(Exception::class);
    });

    it('chains multiple error handlers', function () {
        $handler1Called = false;
        $handler2Called = false;

        $promise = async(function () {
            throw new RuntimeException('Chained error');
        });

        $chainedPromise = $promise
            ->catch(function (Throwable $e) use (&$handler1Called) {
                $handler1Called = true;
                throw $e; // Re-throw to next handler
            })
            ->catch(function (Throwable $e) use (&$handler2Called) {
                $handler2Called = true;
            });

        try {
            await($chainedPromise);
        } catch (Throwable $e) {
            // Already handled
        }

        expect($handler1Called)->toBeTrue()
            ->and($handler2Called)->toBeTrue();
    });

    it('handles timeout-like scenarios with errors', function () {
        $timedOut = false;

        $promise = async(function () use (&$timedOut) {
            sleep(0.5);
            $timedOut = true;
            throw new RuntimeException('Operation timed out');
        });

        $promise->catch(function (Throwable $e) {
            // Handle timeout
        });

        try {
            await($promise);
        } catch (Throwable $e) {
            // Already handled
        }

        expect($timedOut)->toBeTrue();
    });

    it('recovers from error and continues execution', function () {
        $promise = async(function () {
            try {
                await(async(function () {
                    throw new Exception('Recoverable error');
                }));
            } catch (Throwable $e) {
                // Recover from error
                return 'recovered';
            }
        });

        $result = await($promise);

        expect($result)->toBe('recovered');
    });

    it('handles concurrent errors in multiple async tasks', function () {
        $errors = [];

        $promises = [];
        for ($i = 1; $i <= 3; $i++) {
            $promise = async(function () use ($i) {
                sleep(0.1 * $i);
                throw new RuntimeException("Task $i failed");
            });
            
            $promise->catch(function (Throwable $e) use (&$errors) {
                $errors[] = $e->getMessage();
            });
            
            $promises[] = $promise;
        }

        // Wait for all to complete (they'll all fail)
        foreach ($promises as $promise) {
            try {
                await($promise);
            } catch (Throwable $e) {
                // Already handled by catch
            }
        }

        expect($errors)->toHaveCount(3)
            ->and($errors)->toContain('Task 1 failed', 'Task 2 failed', 'Task 3 failed');
    });

    it('handles error thrown before any async operation', function () {
        $promise = async(function () {
            throw new RuntimeException('Immediate error');
        });

        expect(fn() => await($promise))
            ->toThrow(RuntimeException::class, 'Immediate error');
    });

    it('handles error in deeply nested async calls', function () {
        $promise = async(function () {
            return await(async(function () {
                return await(async(function () {
                    return await(async(function () {
                        throw new Exception('Deep nested error');
                    }));
                }));
            }));
        });

        expect(fn() => await($promise))
            ->toThrow(Exception::class, 'Deep nested error');
    });

    it('allows finally-like cleanup after error', function () {
        $cleanupCalled = false;

        $promise = async(function () use (&$cleanupCalled) {
            try {
                throw new RuntimeException('Error with cleanup');
            } finally {
                $cleanupCalled = true;
            }
        });

        try {
            await($promise);
        } catch (Throwable $e) {
            // Expected
        }

        expect($cleanupCalled)->toBeTrue();
    });

});
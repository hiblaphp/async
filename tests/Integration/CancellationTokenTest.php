<?php

declare(strict_types=1);

use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;
use Hibla\Cancellation\CancellationToken;
use Hibla\Cancellation\CancellationTokenSource;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\PromiseCancelledException;
use Hibla\Promise\Exceptions\TimeoutException;
use Hibla\Promise\Promise;

describe('CancellationToken Integration Tests', function () {
    describe('Basic Cancellation', function () {
        it('tracks and cancels a single promise', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $promise = $token->track(async(function () {
                await(delay(1.0));

                return 'completed';
            }));

            expect($token->getTrackedCount())->toBe(1);
            expect($token->isCancelled())->toBeFalse();

            $cts->cancel();

            expect($token->isCancelled())->toBeTrue();
            expect($token->getTrackedCount())->toBe(0);
            expect($promise->isCancelled())->toBeTrue();
        });

        it('cancels multiple tracked promises at once', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $promise1 = $token->track(async(fn() => await(delay(1.0))));
            $promise2 = $token->track(async(fn() => await(delay(1.0))));
            $promise3 = $token->track(async(fn() => await(delay(1.0))));

            expect($token->getTrackedCount())->toBe(3);

            $cts->cancel();

            expect($promise1->isCancelled())->toBeTrue();
            expect($promise2->isCancelled())->toBeTrue();
            expect($promise3->isCancelled())->toBeTrue();
            expect($token->getTrackedCount())->toBe(0);
        });

        it('immediately cancels promises added after token is cancelled', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $cts->cancel();

            expect($token->isCancelled())->toBeTrue();

            $promise = $token->track(async(fn() => 'should be cancelled'));

            expect($promise->isCancelled())->toBeTrue();
        });
    });

    describe('await() with CancellationToken', function () {
        it('cancels promise using await() with token parameter', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $promise = async(function () use ($token) {
                await(delay(1.0), $token);

                return 'completed';
            });

            $cts->cancelAfter(0.1);

            expect(fn() => await($promise))
                ->toThrow(PromiseCancelledException::class);
        });

        it('allows await() without token parameter', function () {
            $promise = async(function () {
                await(delay(0.05));

                return 'completed';
            });

            $result = await($promise);

            expect($result)->toBe('completed');
        });

        it('passes null token to await() without side effects', function () {
            $promise = async(function () {
                await(delay(0.05), null);

                return 'completed';
            });

            $result = await($promise);

            expect($result)->toBe('completed');
        });

        it('cancels multiple await() calls with same token', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();
            $steps = [];

            $promise = async(function () use ($token, &$steps) {
                await(delay(0.05), $token);
                $steps[] = 'step1';

                await(delay(0.05), $token);
                $steps[] = 'step2';

                await(delay(0.05), $token);
                $steps[] = 'step3';

                return 'completed';
            });

            $cts->cancelAfter(0.08);

            try {
                await($promise);
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PromiseCancelledException $e) {
                expect($steps)->toBe(['step1']);
            }
        });

        it('integrates await() cancellation with Promise::race()', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $results = async(function () use ($token) {
                return await(Promise::race([
                    async(fn() => await(delay(2.0), $token) ?: 'slow'),
                    async(fn() => await(delay(0.1), $token) ?: 'fast'),
                ]));
            });

            $result = await($results);

            expect($result)->toBe('fast');
            expect($token->isCancelled())->toBeFalse();
        });

        it('cancels concurrent operations using await() with token', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();
            $completed = [];

            $promise1 = $token->track(async(function () use ($token, &$completed) {
                await(delay(0.5), $token);
                $completed[] = 'op1';

                return 'op1';
            }));

            $promise2 = $token->track(async(function () use ($token, &$completed) {
                await(delay(0.5), $token);
                $completed[] = 'op2';

                return 'op2';
            }));

            $promise3 = $token->track(async(function () use ($token, &$completed) {
                await(delay(0.5), $token);
                $completed[] = 'op3';

                return 'op3';
            }));

            $cts->cancel();

            expect($promise1->isCancelled())->toBeTrue();
            expect($promise2->isCancelled())->toBeTrue();
            expect($promise3->isCancelled())->toBeTrue();
            expect($completed)->toBe([]);
        });

        it('handles mixed await() calls with and without tokens', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();
            $steps = [];

            $promise = async(function () use ($token, &$steps) {
                await(delay(0.05));
                $steps[] = 'step1';

                await(delay(0.05), $token);
                $steps[] = 'step2';

                await(delay(0.05));
                $steps[] = 'step3';

                return 'completed';
            });

            $cts->cancelAfter(0.08);

            try {
                await($promise);
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PromiseCancelledException $e) {
                expect($steps)->toBe(['step1']);
            }
        });

        it('allows reusing token after clearing cancelled state', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $promise1 = async(fn() => await(delay(0.05), $token));
            $result1 = await($promise1);

            expect($result1)->toBeNull();

            $promise2 = async(function () use ($token) {
                await(delay(0.05), $token);

                return 'second';
            });

            $result2 = await($promise2);

            expect($result2)->toBe('second');
        });

        it('cancels nested async operations with await() token', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();
            $steps = [];

            $promise = async(function () use ($token, &$steps) {
                $steps[] = 'outer-start';

                $inner = async(function () use ($token, &$steps) {
                    $steps[] = 'inner-start';
                    await(delay(0.2), $token);
                    $steps[] = 'inner-end';

                    return 'inner';
                });

                await(delay(0.05), $token);
                $steps[] = 'outer-middle';

                $result = await($inner, $token);
                $steps[] = 'outer-end';

                return $result;
            });

            $cts->cancelAfter(0.1);

            try {
                await($promise);
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PromiseCancelledException $e) {
                expect($steps)->toContain('outer-start');
                expect($steps)->toContain('inner-start');
                expect($steps)->not->toContain('inner-end');
                expect($steps)->not->toContain('outer-end');
            }
        });
    });

    describe('Promise Tracking', function () {
        it('automatically untracks promises when they resolve', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $promise = $token->track(async(fn() => 'quick'));

            expect($token->getTrackedCount())->toBe(1);

            $result = await($promise);

            expect($result)->toBe('quick');
            expect($token->getTrackedCount())->toBe(0);
        });

        it('automatically untracks promises when they reject', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $promise = $token->track(async(function () {
                throw new RuntimeException('error');
            })->catch(fn(RuntimeException $e) => $e));

            expect($token->getTrackedCount())->toBe(1);

            try {
                await($promise);
            } catch (RuntimeException $e) {
                expect($e->getMessage())->toBe('error');
            }

            expect($token->getTrackedCount())->toBe(0);
        });

        it('can manually untrack a promise', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $promise = $token->track(async(fn() => await(delay(1.0))));

            expect($token->getTrackedCount())->toBe(1);

            $token->untrack($promise);

            expect($token->getTrackedCount())->toBe(0);

            $cts->cancel();

            expect($promise->isCancelled())->toBeFalse();
        });

        it('clears all tracked promises without cancelling them', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $promise1 = $token->track(async(fn() => await(delay(1.0))));
            $promise2 = $token->track(async(fn() => await(delay(1.0))));

            expect($token->getTrackedCount())->toBe(2);

            $token->clearTracked();

            expect($token->getTrackedCount())->toBe(0);

            $cts->cancel();

            expect($promise1->isCancelled())->toBeFalse();
            expect($promise2->isCancelled())->toBeFalse();
        });
    });

    describe('Cancellation Callbacks', function () {
        it('executes callback when cancelled', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();
            $executed = false;

            $token->onCancel(function () use (&$executed) {
                $executed = true;
            });

            expect($executed)->toBeFalse();

            $cts->cancel();

            expect($executed)->toBeTrue();
        });

        it('executes multiple callbacks in order', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();
            $order = [];

            $token->onCancel(function () use (&$order) {
                $order[] = 'first';
            });
            $token->onCancel(function () use (&$order) {
                $order[] = 'second';
            });
            $token->onCancel(function () use (&$order) {
                $order[] = 'third';
            });

            $cts->cancel();

            expect($order)->toBe(['first', 'second', 'third']);
        });

        it('immediately executes callback if token already cancelled', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $cts->cancel();

            $executed = false;

            $token->onCancel(function () use (&$executed) {
                $executed = true;
            });

            expect($executed)->toBeTrue();
        });

        it('clears callbacks after cancellation', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();
            $count = 0;

            $token->onCancel(function () use (&$count) {
                $count++;
            });

            $cts->cancel();
            expect($count)->toBe(1);

            $cts->cancel();
            expect($count)->toBe(1);
        });
    });

    describe('throwIfCancelled()', function () {
        it('throws exception if token is cancelled', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $cts->cancel();

            expect(fn() => $token->throwIfCancelled())
                ->toThrow(PromiseCancelledException::class, 'Operation was cancelled');
        });

        it('does not throw if token is not cancelled', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $token->throwIfCancelled();

            expect(true)->toBeTrue();
        });

        it('can be used to check cancellation in async operations', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();
            $steps = [];

            $promise = async(function () use ($token, &$steps) {
                $steps[] = 'step1';
                await(delay(0.05));

                $token->throwIfCancelled();

                $steps[] = 'step2';
                await(delay(0.05));

                return 'completed';
            });

            usleep(60000);
            $cts->cancel();

            try {
                await($promise);
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PromiseCancelledException $e) {
                expect($e->getMessage())->toBe('Operation was cancelled');
                expect($steps)->toBe(['step1']);
            }
        });
    });

    describe('Real-world Scenarios', function () {
        it('cancels long-running operations', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();
            $operationsCompleted = 0;

            $promises = [];
            for ($i = 0; $i < 5; $i++) {
                $promises[] = $token->track(async(function () use (&$operationsCompleted, $i) {
                    await(delay(0.5));
                    $operationsCompleted++;

                    return "operation-$i";
                }));
            }

            usleep(100000);
            $cts->cancel();

            foreach ($promises as $promise) {
                expect($promise->isCancelled())->toBeTrue();
            }

            expect($operationsCompleted)->toBe(0);
        });

        it('handles partial completion before cancellation', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();
            $completed = [];

            await(async(function () use ($cts, $token, &$completed) {
                $fast = $token->track(async(function () use (&$completed) {
                    await(delay(0.05));
                    $completed[] = 'fast';

                    return 'fast';
                }));

                $slow = $token->track(async(function () use (&$completed) {
                    await(delay(0.3));
                    $completed[] = 'slow';

                    return 'slow';
                }));

                await($fast);
                $cts->cancel();

                expect($completed)->toBe(['fast']);
                expect($slow->isCancelled())->toBeTrue();
            }));
        });

        it('supports cascading cancellation', function () {
            $parentCts = new CancellationTokenSource();
            $parentToken = $parentCts->token();

            $childCts = new CancellationTokenSource();
            $childToken = $childCts->token();

            $parentToken->onCancel(fn() => $childCts->cancel());

            $parentPromise = $parentToken->track(async(fn() => await(delay(1.0))));
            $childPromise = $childToken->track(async(fn() => await(delay(1.0))));

            $parentCts->cancel();

            expect($parentPromise->isCancelled())->toBeTrue();
            expect($childPromise->isCancelled())->toBeTrue();
            expect($childToken->isCancelled())->toBeTrue();
        });

        it('integrates with Promise::race() for timeout pattern', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $operation = $token->track(async(function () {
                await(delay(1.0));

                return 'completed';
            }));

            $timeout = delay(0.1)->then(function () use ($cts) {
                $cts->cancel();

                throw new RuntimeException('Timeout');
            });

            try {
                await(Promise::race([$operation, $timeout]));
                expect(false)->toBeTrue('Should have timed out');
            } catch (RuntimeException $e) {
                expect($e->getMessage())->toBe('Timeout');
                expect($operation->isCancelled())->toBeTrue();
            }
        });

        it('implements timeout pattern using await() with token', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $operation = async(function () use ($token) {
                await(delay(1.0), $token);

                return 'completed';
            });

            $timeoutSeconds = 0.1;
            $timeout = delay($timeoutSeconds)->then(function () use ($cts, $timeoutSeconds) {
                $cts->cancel();

                throw new TimeoutException($timeoutSeconds);
            });

            try {
                await(Promise::race([$operation, $timeout]));
                expect(false)->toBeTrue('Should have timed out');
            } catch (TimeoutException $e) {
                expect($e->getMessage())->toBe("Operation timed out after {$timeoutSeconds} seconds");
            }
        });

        it('allows resource cleanup on cancellation', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();
            $resourceReleased = false;

            $token->onCancel(function () use (&$resourceReleased) {
                $resourceReleased = true;
            });

            $promise = $token->track(async(fn() => await(delay(1.0))));

            $cts->cancel();

            expect($resourceReleased)->toBeTrue();
            expect($promise->isCancelled())->toBeTrue();
        });

        it('handles complex workflow cancellation', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();
            $workflow = [];

            $promise = async(function () use ($token, &$workflow) {
                $workflow[] = 'start';

                await($token->track(delay(0.05)));
                $workflow[] = 'data1';

                $token->throwIfCancelled();

                await($token->track(delay(0.05)));
                $workflow[] = 'data2';

                $token->throwIfCancelled();

                return 'completed';
            });

            $cts->cancelAfter(0.06);

            try {
                await($promise);
                expect(false)->toBeTrue('Should have been cancelled');
            } catch (PromiseCancelledException $e) {
                expect($workflow)->toBe(['start', 'data1']);
            }
        });

        it('handles complex workflow with await() token parameter', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();
            $workflow = [];

            $promise = async(function () use ($token, &$workflow) {
                $workflow[] = 'start';

                await(delay(0.05), $token);
                $workflow[] = 'data1';

                $token->throwIfCancelled();

                await(delay(0.05), $token);
                $workflow[] = 'data2';

                $token->throwIfCancelled();

                return 'completed';
            });

            $cts->cancelAfter(0.06);

            try {
                await($promise);
                expect(false)->toBeTrue('Should have been cancelled');
            } catch (PromiseCancelledException $e) {
                expect($workflow)->toBe(['start', 'data1']);
            }
        });
    });

    describe('Edge Cases', function () {
        it('handles double cancellation gracefully', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();
            $cancelCount = 0;

            $token->onCancel(function () use (&$cancelCount) {
                $cancelCount++;
            });

            $cts->cancel();
            $cts->cancel();

            expect($cancelCount)->toBe(1);
            expect($token->isCancelled())->toBeTrue();
        });

        it('does not cancel already settled promises', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $resolved = Promise::resolved('value');
            $rejected = Promise::rejected(new RuntimeException('error'));

            $token->track($resolved);
            $token->track($rejected);

            $cts->cancel();

            expect($resolved->isFulfilled())->toBeTrue();
            expect($resolved->isCancelled())->toBeFalse();
            expect($rejected->isRejected())->toBeTrue();
            expect($rejected->isCancelled())->toBeFalse();
            expect($rejected->getReason())->toBeInstanceOf(RuntimeException::class);
        });

        it('handles tracking the same promise multiple times', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $promise = async(fn() => await(delay(1.0)));

            $token->track($promise);
            $token->track($promise);
            $token->track($promise);

            expect($token->getTrackedCount())->toBe(3);

            $cts->cancel();

            expect($promise->isCancelled())->toBeTrue();
        });

        it('works with immediately resolved promises', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $promise = $token->track(Promise::resolved('immediate'));

            expect($token->getTrackedCount())->toBe(0);
            expect($promise->getValue())->toBe('immediate');
        });

        it('handles await() with cancelled token before delay starts', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $cts->cancel();

            $promise = async(function () use ($token) {
                await(delay(0.1), $token);

                return 'should not reach';
            });

            expect(fn() => await($promise))
                ->toThrow(PromiseCancelledException::class);
        });

        it('works when await() token is null explicitly', function () {
            $token = null;

            $promise = async(function () use ($token) {
                await(delay(0.05), $token);

                return 'completed';
            });

            $result = await($promise);

            expect($result)->toBe('completed');
        });
    });

    describe('CancellationTokenSource Features', function () {
        it('creates token source with timeout', function () {
            $cts = new CancellationTokenSource(0.1);
            $token = $cts->token();

            expect($token->isCancelled())->toBeFalse();

            await(delay(0.15));

            expect($token->isCancelled())->toBeTrue();
        });

        it('creates linked token source that cancels when any parent cancels', function () {
            $cts1 = new CancellationTokenSource();
            $cts2 = new CancellationTokenSource();

            $linkedCts = CancellationTokenSource::createLinkedTokenSource(
                $cts1->token(),
                $cts2->token()
            );
            $linkedToken = $linkedCts->token();

            expect($linkedToken->isCancelled())->toBeFalse();

            $cts1->cancel();

            expect($linkedToken->isCancelled())->toBeTrue();
        });

        it('creates linked token source with already cancelled token', function () {
            $cts1 = new CancellationTokenSource();
            $cts1->cancel();

            $cts2 = new CancellationTokenSource();

            $linkedCts = CancellationTokenSource::createLinkedTokenSource(
                $cts1->token(),
                $cts2->token()
            );

            expect($linkedCts->token()->isCancelled())->toBeTrue();
        });

        it('allows resetting cancelAfter timeout', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $cts->cancelAfter(0.05);
            $cts->cancelAfter(0.2);

            expect($token->isCancelled())->toBeFalse();
            Loop::run();
            expect($token->isCancelled())->toBeTrue();
        });

        it('provides CancellationToken::none() for non-cancellable operations', function () {
            $token = CancellationToken::none();

            expect($token->isCancelled())->toBeFalse();

            $promise = async(function () use ($token) {
                await(delay(0.05), $token);
                return 'completed';
            });

            $result = await($promise);
            expect($result)->toBe('completed');
        });
    });

    describe('CancellationTokenRegistration', function () {
        it('allows disposing of callback registration', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();
            $called = false;

            $registration = $token->onCancel(function () use (&$called) {
                $called = true;
            });

            $registration->dispose();

            $cts->cancel();

            expect($called)->toBeFalse();
        });

        it('handles disposing registration multiple times', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $registration = $token->onCancel(fn() => null);

            $registration->dispose();
            $registration->dispose();

            expect(true)->toBeTrue();
        });

        it('returns pre-disposed registration for already cancelled token', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token();

            $cts->cancel();

            $called = false;
            $registration = $token->onCancel(function () use (&$called) {
                $called = true;
            });

            expect($called)->toBeTrue();

            $registration->dispose();

            expect(true)->toBeTrue();
        });
    });
});

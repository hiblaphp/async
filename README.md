# Hibla Async

**Context-independent async/await for PHP without function coloring.**

`hiblaphp/async` brings `async` and `await` to PHP as plain functions built on top of PHP 8.1 Fibers and the Hibla event loop. Unlike JavaScript, Python, or C#, `await()` works in both fiber and non-fiber contexts. You write normal functions and lift them into concurrency at the call site, not inside the function definition.

[![Latest Release](https://img.shields.io/github/release/hiblaphp/async.svg?style=flat-square)](https://github.com/hiblaphp/async/releases)
[![Tests](https://github.com/hiblaphp/async/actions/workflows/test.yml/badge.svg)](https://github.com/hiblaphp/async/actions/workflows/test.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/hiblaphp/async.svg?style=flat-square)](https://packagist.org/packages/hiblaphp/async)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

## Contents

**Getting started**

- [Installation](#installation)
- [Quick Example](#quick-example)
- [Introduction](#introduction)
- [The Function Coloring Problem](#the-function-coloring-problem)
- [Fibers and Coroutines](#fibers-and-coroutines)

**Core usage**

- [`async()` — Running Code Concurrently](#async--running-code-concurrently)
  - [One fiber runs at a time — never block inside `async()`](#one-fiber-runs-at-a-time--never-block-inside-async)
  - [Exceptions inside `async()`](#exceptions-inside-async)
  - [Avoid unnecessary wrapping](#avoid-unnecessary-wrapping)
- [`await()` — Suspending Until a Promise Settles](#await--suspending-until-a-promise-settles)
  - [Context-independent behavior](#context-independent-behavior)
  - [Rejection and cancellation](#rejection-and-cancellation)
  - [With `CancellationToken`](#with-cancellationtoken)
- [No Function Coloring in Practice](#no-function-coloring-in-practice)

**Features**

- [`asyncFn()` — Wrapping a Callable](#asyncfn--wrapping-a-callable)
- [`sleep()` — Async-Aware Pause](#sleep--async-aware-pause)
- [`inFiber()` — Context Detection](#infiber--context-detection)
- [Cancellation inside `async()`](#cancellation-inside-async)
  - [Automatic resource cleanup without `track()`](#automatic-resource-cleanup-without-track)
- [Combining with Promise Combinators](#combining-with-promise-combinators)

**Testing**

- [Testing Async Code](#testing-async-code)

**Reference**

- [Comparison with JavaScript async/await](#comparison-with-javascript-asyncawait)
- [API Reference](#api-reference)

**Meta**

- [Development](#development)
- [Credits](#credits)
- [License](#license)

---

## Installation

>This package is currently in **beta**. Before installing, ensure your `composer.json`
allows beta releases:

```json
{
    "minimum-stability": "beta",
    "prefer-stable": true
}
```

```bash
composer require hiblaphp/async
```

**Requirements:**
- PHP 8.4+ 

---

## Quick Example

```php
use function Hibla\async;
use function Hibla\await;
use function Hibla\sleep;

// Fetch three resources concurrently — each async() runs in its own Fiber
[$user, $orders, $stats] = await(Promise::all([
    async(fn() => fetchUser(1)),
    async(fn() => fetchOrders(1)),
    async(fn() => fetchStats(1)),
]));

// Write sequential async logic that reads like synchronous code
$report = await(async(function () {
    $user   = await(fetchUser(1));
    $orders = await(fetchOrders($user->id));

    sleep(0.5); // suspends this Fiber — other work continues in the background

    return generateReport($user, $orders);
}));

// Outside a Fiber, await() holds the script here while the event loop
// keeps running underneath — timers fire, other in-flight work continues
$user = await(fetchUser(1));
echo $user->name;
```

The four things to notice:

- `async()` runs a block of code in its own Fiber and returns a `Promise`.
- `await()` suspends the current Fiber until a promise settles or holds the script at that line when called at the top level, while the event loop keeps running underneath.
- `Promise::all()` waits for multiple async tasks concurrently and gives you all results at once.
- Functions that use `await()` need no special marking — the caller decides whether to give them concurrency by wrapping in `async()`.

The rest of this document covers each of these in detail.

---

## Introduction

PHP has always been synchronous. When your code calls an HTTP endpoint, reads a file, or queries a database, it blocks and waits. One operation at a time, in sequence, from top to bottom. For short-lived scripts and simple request handlers this is fine. But the moment you need to fetch multiple things at once, handle WebSocket connections, or run background jobs without spinning up new processes, the model falls apart.

The standard solution in most languages is `async/await`: a way to mark functions as asynchronous and pause them at I/O boundaries while other work proceeds. But every major language that has implemented this (JavaScript, Python, C#) has introduced what is known as **function coloring**. `async` and `await` are syntax keywords that live inside the function definition. The moment a function uses `await`, it must be marked `async`, which changes its return type, which forces every caller to also be `async`. The color spreads upward through the entire call stack, creating two incompatible worlds (sync code and async code) that cannot be mixed freely.

`hiblaphp/async` solves this differently. `async()` and `await()` are plain PHP functions, not keywords. `await()` is context-independent: it checks whether it is running inside a Fiber at runtime and behaves accordingly. Inside a Fiber it suspends cooperatively. Outside a Fiber it holds the script at that line while the event loop keeps running underneath and timers fire, I/O callbacks run, and other in-flight work continues normally while it waits. A function that calls `await()` has no special marking, no changed return type, and no impact on its callers. The caller decides whether to give it concurrency by wrapping it in `async()` at the call site. The color lives at the call site, not inside the function.

This library is the top of the Hibla async stack. It sits on `hiblaphp/event-loop` for fiber scheduling, `hiblaphp/promise` for the promise model, and `hiblaphp/cancellation` for external cancellation coordination. Together these four libraries give you a complete async programming model for PHP that reads like synchronous code but runs cooperatively under the hood.

---

## The Function Coloring Problem

In JavaScript, Python, and C#, `async` and `await` are keywords that live inside the function definition. The moment a function uses `await`, it must be marked `async`, which changes its return type, which forces every caller to also be `async`. The color spreads upward through the entire call stack:

```js
// JavaScript — color spreads upward through every layer
async function getUser(id) {
  // must be async
  return await fetchUser(id); // uses await
}

async function buildPage(userId) {
  // must be async because getUser is async
  const user = await getUser(userId);
  return user;
}

async function handleRequest(req) {
  // must be async because buildPage is async
  const page = await buildPage(req.userId);
  return page;
}
```

Hibla solves this entirely. `await()` is just a regular PHP function that checks its execution context at runtime. A function that uses `await()` has no special marking, no changed return type, and no impact on its callers. The caller decides whether to give it concurrency by wrapping it in `async()` at the call site:

```php
use function Hibla\async;
use function Hibla\await;

// A plain function — no special marking, no color
function getUser(int $id): User
{
    return await(fetchUser($id));
}

// Works synchronously at the top level — no async() needed
$user = getUser(1);

// Works concurrently when wrapped in async() — no changes to getUser()
$promise = async(fn() => getUser(1));
```

The color lives at the call site, not inside the function. This means you can write your entire application using normal functions with `await()` and introduce concurrency selectively where you need it.

---

## Fibers and Coroutines

PHP Fibers were introduced in PHP 8.1 as a first-class stackful coroutine primitive. A **stackful coroutine** is a unit of execution that can be suspended and resumed at any point in its call stack, including inside deeply nested function calls. This is what separates Fibers from generators.

A generator can only suspend at the top-level `yield` inside the generator function itself. A Fiber can suspend from anywhere in its call stack. When a Fiber suspends, the entire call stack at that point (every function frame, every local variable, every instruction pointer) is frozen and preserved. When the Fiber is resumed, execution continues from exactly where it left off as if nothing happened.

A Fiber also has its own separate C-level stack, independent from the main thread stack, which is what makes suspension at any depth possible.

`async()` creates a Fiber and schedules it on the event loop. When the Fiber calls `await()` on a pending promise, it calls `Fiber::suspend()` internally, freezing the entire call stack and returning control to the event loop. When the promise resolves, `Loop::scheduleFiber()` queues the Fiber to be resumed, and the event loop restores the full call stack and continues execution from the suspension point:

```php
function fetchUserProfile(int $id): PromiseInterface
{
    return async(function () use ($id) {
        $user   = await(Http::get("/users/$id"));
        $avatar = await(Http::get("/avatars/$id"));

        return ['user' => $user, 'avatar' => $avatar];
    });
}

async(function () {
    // Multiple profiles load concurrently because each async() call
    // runs in its own Fiber and suspends independently at each await()
    [$page1, $page2, $page3] = await(Promise::all([
        fetchUserProfile(1),
        fetchUserProfile(2),
        fetchUserProfile(3),
    ]));
});
```

---

## `async()` — Running Code Concurrently

`async()` wraps a callable in a PHP Fiber, schedules it on the event loop, and returns a `Promise` that resolves with the callable's return value. The callable does not run immediately. It is queued in the Fiber phase of the next event loop iteration:

```php
use function Hibla\async;

$promise = async(function () {
    return 'hello from a fiber';
});

$promise->then(fn($value) => print($value)); // hello from a fiber
```

Multiple `async()` calls run concurrently. Each one gets its own Fiber and yields to others at every `await()` point:

```php
$start = microtime(true);

async(function () {
    await(delay(1));
    echo "Task 1 done\n";
});

async(function () {
    await(delay(1));
    echo "Task 2 done\n";
});

async(function () {
    await(delay(1));
    echo "Task 3 done\n";
});

// All three run concurrently — total time ~1 second, not 3
echo microtime(true) - $start; // ~1.0
```

---

### One fiber runs at a time — never block inside `async()`

The event loop runs only one Fiber at a time. Fibers are cooperatively scheduled: a Fiber runs until it explicitly suspends via `await()` or `sleep()`, at which point the event loop picks up the next ready Fiber.

A **blocking call** inside a Fiber (PHP's native `sleep()`, a synchronous database query, `file_get_contents()`, or any other call that blocks the OS thread) stalls the **entire event loop** for its duration. No other Fiber runs, no timers fire, no I/O is processed until the blocking call returns:

```php
// Wrong — blocks the entire loop for 2 seconds
async(function () {
    \sleep(2); // PHP's native sleep — stalls everything
    echo "done\n";
});

// Correct — suspends this Fiber cooperatively, loop stays free
async(function () {
    sleep(2); // Hibla's sleep — use function Hibla\sleep
    echo "done\n";
});
```

Always use the async-aware equivalents from the Hibla ecosystem: `Http::get()` instead of `file_get_contents()`, `await(delay($n))` instead of `\sleep($n)`, stream watchers via `hiblaphp/stream` instead of blocking `fread()`. If you need to run genuinely blocking work or CPU-bound tasks, offload them to a separate process via `hiblaphp/parallel` rather than running them inside a Fiber.

---

### Exceptions inside `async()`

Any exception thrown inside an `async()` block rejects the returned promise. Always attach a `catch()` handler or `await()` the promise inside a `try/catch` when you care about errors:

```php
$promise = async(function () {
    throw new \RuntimeException('Something went wrong');
});

$promise->catch(fn($e) => print($e->getMessage())); // Something went wrong
```

```php
async(function () {
    try {
        $result = await(riskyOperation());
        return $result;
    } catch (\Throwable $e) {
        logError($e);
        return null;
    }
});
```

---

### Avoid unnecessary wrapping

Each `async()` call creates a new PHP Fiber. Fibers are lightweight but not free. Each one allocates a C-level stack and associated runtime state. Creating a Fiber just to immediately await a single promise that already exists adds overhead with no benefit.

If a function already returns a promise, `await()` it directly:

```php
// Wrong — allocates a full Fiber just to await one existing promise
$result = await(async(fn() => await(Http::get('/api/data'))));

// Correct — await the promise directly, no Fiber needed
$result = await(Http::get('/api/data'));
```

The same applies to plain functions that use `await()` internally. They already work in both sync and async contexts without wrapping:

```php
function getUserName(int $id): string
{
    $user = await(fetchUser($id));
    return $user->name;
}

// Wrong — getUserName() already works in both contexts
$name = await(async(fn() => getUserName(1)));

// Correct — call it directly
$name = getUserName(1);

// Only wrap in async() when you specifically want concurrent execution
$promise = async(fn() => getUserName(1)); // justified — explicit concurrency
```

Use `async()` when you genuinely need a Fiber: when you need to await multiple promises sequentially with logic in between, or when you want a block of code to run concurrently as its own unit of work:

```php
// Good use — multiple awaits with logic between them
$promise = async(function () {
    $user    = await(fetchUser(1));
    $orders  = await(fetchOrders($user->id));
    $ratings = await(fetchRatings($user->id));

    return processData($user, $orders, $ratings);
});
```

---

## `await()` — Suspending Until a Promise Settles

`await()` suspends the current Fiber until the given promise settles, then returns the resolved value or throws the rejection reason:

```php
use function Hibla\await;

$user = await(fetchUser(1));
echo $user->name;
```

---

### Context-independent behavior

`await()` checks `Fiber::getCurrent()` at runtime and behaves accordingly:

- **Inside a Fiber** (`async()` block): suspends the Fiber cooperatively. The event loop continues running, so other fibers, timers, and I/O all proceed while this Fiber waits.
- **Outside a Fiber** (top level or sync function): holds the script at that line and drives the event loop until the promise settles. The event loop remains fully alive underneath — timers fire, I/O callbacks run, and other in-flight work continues normally while it waits.

```php
// Outside a Fiber — holds the script here, event loop keeps running underneath
$user = await(fetchUser(1));

// Inside a Fiber — suspends cooperatively
async(function () {
    $user = await(fetchUser(1)); // other work runs while waiting
    echo $user->name;
});
```

This context-independence is what eliminates function coloring. A function that calls `await()` works correctly regardless of where it is called from. It does not need to know or care whether it is inside a Fiber.

---

### Rejection and cancellation

If the awaited promise rejects, `await()` throws the rejection reason:

```php
async(function () {
    try {
        $user = await(fetchUser(999)); // rejects with NotFoundException
    } catch (\NotFoundException $e) {
        echo "User not found\n";
    }
});
```

If the promise is cancelled before or during the await, `await()` throws `CancelledException`:

```php
async(function () use ($token) {
    try {
        $user = await(fetchUser(1), $token);
    } catch (\Hibla\Promise\Exceptions\CancelledException $e) {
        echo "Fetch was cancelled\n";
    }
});
```

---

### With `CancellationToken`

Pass a `CancellationToken` as the second argument to automatically track the promise against the token. If the token is cancelled while the Fiber is suspended, the promise is cancelled and `CancelledException` is thrown at the `await()` call site, with no manual `token->track()` needed:

```php
use Hibla\Cancellation\CancellationTokenSource;
use function Hibla\async;
use function Hibla\await;

$cts = new CancellationTokenSource(5.0); // 5 second timeout

async(function () use ($cts) {
    try {
        $user   = await(fetchUser(1), $cts->token);
        $orders = await(fetchOrders($user->id), $cts->token);

        return compact('user', 'orders');
    } catch (\Hibla\Promise\Exceptions\CancelledException $e) {
        echo "Operation timed out or was cancelled\n";
    }
});
```

---

## No Function Coloring in Practice

The full power of the no-coloring design becomes clear when you write library code that uses `await()` internally. The same code works in every context without any changes:

```php
// Plain functions using await() internally — no special marking
function getUser(int $id): User
{
    return await(Http::get("/users/$id")->then(
        fn($r) => User::fromArray(json_decode($r->getBody(), true))
    ));
}

function getUserWithOrders(int $id): array
{
    $user   = getUser($id);
    $orders = await(fetchOrders($user->id));

    return compact('user', 'orders');
}
```

These are plain functions. Callers can use them in any of these ways without any changes to the functions themselves:

```php
// 1. Synchronous — holds the script at each call, event loop keeps running underneath
$data = getUserWithOrders(1);

// 2. Single async task — runs in a Fiber, suspends cooperatively
$promise = async(fn() => getUserWithOrders(1));

// 3. Concurrent — multiple users fetched concurrently
$promises = array_map(
    fn($id) => async(fn() => getUserWithOrders($id)),
    [1, 2, 3, 4, 5]
);
await(Promise::all($promises));

// 4. With concurrency limiting
await(Promise::concurrent(
    array_map(
        fn($id) => fn() => async(fn() => getUserWithOrders($id)),
        range(1, 100)
    ),
    concurrency: 10
));
```

The functions never changed. The concurrency strategy is entirely decided by the caller.

---

## `asyncFn()` — Wrapping a Callable

`asyncFn()` wraps a callable so that every call to it automatically runs inside `async()` and returns a `Promise`. Useful when you want to convert an existing function into a reusable async factory without changing the original function.

The same performance considerations from the [avoid unnecessary wrapping](#avoid-unnecessary-wrapping) section apply. Only use it when the wrapped function genuinely needs its own Fiber context for concurrent execution:

```php
use function Hibla\asyncFn;

function processRecord(array $record): array
{
    $enriched  = await(enrichRecord($record));
    $validated = await(validateRecord($enriched));

    return $validated;
}

// Create an async version without changing processRecord()
$asyncProcess = asyncFn('processRecord');

// Primary use case: passing to Promise::map() or Promise::concurrent()
await(Promise::map($records, $asyncProcess, concurrency: 10));
```

---

## `sleep()` — Async-Aware Pause

The `sleep()` function from `hiblaphp/async` is an async-aware replacement for PHP's native `sleep()`. It accepts fractional seconds: `sleep(0.5)` for 500ms, `sleep(1.5)` for 1.5 seconds.

- **Inside a Fiber:** suspends the current Fiber cooperatively. The event loop continues, so other fibers, timers, and I/O run while this Fiber waits.
- **Outside a Fiber:** holds the script at that line while the event loop keeps running underneath, timers fire, I/O callbacks run, and other in-flight work continues normally while it waits.

```php
use function Hibla\sleep;

async(function () {
    echo "Task 1 start\n";
    sleep(2);
    echo "Task 1 done\n";
});

async(function () {
    echo "Task 2 start\n";
    sleep(1);
    echo "Task 2 done\n"; // runs before Task 1
});

// Output:
// Task 1 start
// Task 2 start
// Task 2 done  (~1 second)
// Task 1 done  (~2 seconds)
// Total time: ~2 seconds, not 3
```

> **Important:** Always import `Hibla\sleep` explicitly. PHP's native `sleep()` and Hibla's `sleep()` have the same name. If you forget the import you will silently call PHP's native blocking `sleep()` instead, stalling the entire event loop with no error or warning:
>
> ```php
> use function Hibla\sleep; // required — do not omit
>
> async(function () {
>     sleep(1);  // Hibla's sleep — correct
>     \sleep(1); // PHP's native sleep — stalls the entire loop
> });
> ```

---

## `inFiber()` — Context Detection

`inFiber()` returns `true` if the current code is executing inside a PHP Fiber. Useful for writing code that needs to behave differently depending on whether it is in an async context:

```php
use function Hibla\inFiber;

function getStatus(): string
{
    if (inFiber()) {
        return await(fetchStatusAsync());
    }

    return fetchStatusSync();
}
```

In most cases you will not need this. `await()` already handles both contexts automatically. `inFiber()` is primarily useful when you want to select between fundamentally different implementations rather than just different blocking behaviors.

---

## Cancellation inside `async()`

Pass a `CancellationToken` to `await()` calls inside `async()` blocks to support external cancellation of the entire workflow. When the token is cancelled, the current `await()` throws `CancelledException` and the Fiber unwinds naturally through any `catch` or `finally` blocks.

Use `finally` inside `async()` to guarantee cleanup runs whether the workflow completes normally, throws, or is cancelled:

```php
use Hibla\Cancellation\CancellationTokenSource;
use function Hibla\async;
use function Hibla\await;

$cts = new CancellationTokenSource();

$workflow = async(function () use ($cts) {
    $connection = openConnection();

    try {
        $user   = await(fetchUser(1), $cts->token);
        $orders = await(fetchOrders($user->id), $cts->token);
        $report = await(generateReport($user, $orders), $cts->token);

        return $report;
    } catch (\Hibla\Promise\Exceptions\CancelledException $e) {
        echo "Workflow cancelled\n";
        return null;
    } finally {
        // Always runs — normal completion, exception, or cancellation
        $connection->close();
    }
});

// Cancel from anywhere — the next await() in the workflow throws
Loop::addTimer(2.0, fn() => $cts->cancel());

$result = await($workflow);
```

If the token is already cancelled before the first `await()` inside the Fiber runs, the first `await()` call throws `CancelledException` immediately without suspending.

---

### Automatic resource cleanup without `track()`

When you pass a token to `await()`, the promise is automatically tracked by the token, so you do not need to call `token->track($promise)` manually. This is particularly useful when awaiting promises that already have `onCancel()` handlers registered internally, such as HTTP requests from `hiblaphp/http-client`. The token triggers the promise's own `onCancel()` cleanup without any extra wiring at the call site:

```php
$cts = new CancellationTokenSource(5.0);

$workflow = async(function () use ($cts) {
    // Http::get() has an onCancel() handler that aborts the curl request.
    // Passing $cts->token to await() is enough — no track() needed.
    $response = await(Http::get('https://api.example.com/users'), $cts->token);
    $data     = await(Http::get('https://api.example.com/orders'), $cts->token);

    return compact('response', 'data');
});
```

Passing the token directly to `await()` is the preferred pattern inside `async()` blocks. It is more concise and keeps the cancellation wiring at the `await()` call site where the suspension happens.

---

## Combining with Promise Combinators

`async()` returns a standard `Promise` so it composes naturally with all of `hiblaphp/promise`'s collection and concurrency methods.

### Running tasks concurrently with `Promise::all()`

```php
[$users, $products, $stats] = await(Promise::all([
    async(fn() => fetchUsers()),
    async(fn() => fetchProducts()),
    async(fn() => fetchStats()),
]));
```

### Concurrency limiting with `Promise::concurrent()`

```php
$results = await(Promise::concurrent(
    array_map(
        fn($id) => fn() => async(function () use ($id) {
            $user   = await(fetchUser($id));
            $orders = await(fetchOrders($user->id));

            return compact('user', 'orders');
        }),
        range(1, 100)
    ),
    concurrency: 10
));
```

### Racing with `Promise::race()`

```php
$fastest = await(Promise::race([
    async(fn() => fetchFromRegionA()),
    async(fn() => fetchFromRegionB()),
    async(fn() => fetchFromRegionC()),
]));
```

### Timeout with `Promise::timeout()`

```php
$cts = new CancellationTokenSource();

try {
    $result = await(Promise::timeout(
        async(function () use ($cts) {
            return await(slowOperation(), $cts->token);
        }),
        seconds: 5.0
    ));
} catch (\Hibla\Promise\Exceptions\TimeoutException $e) {
    echo "Operation timed out\n";
}
```

---

## Testing Async Code

Because `await()` holds the script at that line and drives the event loop when called outside a Fiber, you can test async code directly without any special test runner setup, event loop runner, or test helpers. Just call `await()` at the test level and it drives the loop until the promise settles:

```php
public function test_fetch_user(): void
{
    $user = await(fetchUser(1));

    $this->assertEquals('John', $user->name);
}

public function test_concurrent_fetch(): void
{
    [$user, $orders] = await(Promise::all([
        fetchUser(1),
        fetchOrders(1),
    ]));

    $this->assertNotEmpty($orders);
}

public function test_cancellation(): void
{
    $cts = new CancellationTokenSource();
    $cts->cancel();

    $this->expectException(\Hibla\Promise\Exceptions\CancelledException::class);

    await(fetchUser(1), $cts->token);
}
```

This is one of the strongest practical advantages of context-independent `await()`. The same code that runs non-blocking in production runs with the event loop driven synchronously in tests, with no adaptation required.

---

## Comparison with JavaScript async/await

|                                  | JavaScript                    | Hibla                                                  |
| -------------------------------- | ----------------------------- | ------------------------------------------------------ |
| `await` usable in sync functions | No, syntax error              | Yes, holds script while event loop keeps running       |
| Function coloring                | Yes, spreads upward           | No, color lives at call site                           |
| Marking a function async         | Required (`async function`)   | Not required                                           |
| Return type change               | Yes, always returns `Promise` | No, return type unchanged                              |
| Concurrency primitive            | `async function`              | `async(fn() => ...)` at call site                      |
| Already-settled promise          | Returns on next microtask     | Returns immediately, no suspension                     |
| Context detection                | Not available                 | `inFiber()`                                            |
| Testing async code               | Requires async test runner    | Plain `await()`, event loop runs underneath, no setup  |

---

## API Reference

| Function                                                             | Description                                                                                                                                                                                                                                                                                                                           |
| -------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `async(callable $function): PromiseInterface`                        | Wrap a callable in a Fiber and schedule it on the event loop. Returns a Promise that resolves with the callable's return value. The callable does not run immediately; it is queued in the next Fiber phase.                                                                                                                          |
| `await(PromiseInterface $promise, ?CancellationToken $token): mixed` | Inside a Fiber: suspends cooperatively until the promise settles. Outside a Fiber: holds the script at that line and drives the event loop until the promise settles and timers fire and other in-flight work continues normally. Returns immediately without suspending for already-settled promises. Throws on rejection or cancellation. |
| `asyncFn(callable $function): callable`                              | Wrap a callable so every call runs inside `async()` and returns a Promise. Creates a new Fiber per call.                                                                                                                                                                                                                              |
| `sleep(float $seconds): void`                                        | Inside a Fiber: suspends cooperatively. Outside a Fiber: holds the script at that line while the event loop keeps running underneath. Accepts fractional seconds. Always import explicitly, as PHP's native `sleep()` has the same name.                                                                                               |
| `inFiber(): bool`                                                    | Returns true if currently executing inside a PHP Fiber.                                                                                                                                                                                                                                                                               |

---

## Development

```bash
git clone https://github.com/hiblaphp/async.git
cd async
composer install
```

```bash
./vendor/bin/pest
```

```bash
./vendor/bin/phpstan analyse
```

---

## Credits

- **API Design:** Inspired by JavaScript's `async`/`await` syntax and the function coloring problem described by Bob Nystrom in ["What Color is Your Function?"](https://journal.stuffwithstuff.com/2015/02/01/what-color-is-your-function/). Hibla's context-independent `await()` is a direct solution to the problem that article described.
- **Fiber Scheduling:** Powered by [hiblaphp/event-loop](https://github.com/hiblaphp/event-loop).
- **Promise Integration:** Built on [hiblaphp/promise](https://github.com/hiblaphp/promise).
- **Cancellation:** Powered by [hiblaphp/cancellation](https://github.com/hiblaphp/cancellation).

---

## License

MIT License. See [LICENSE](./LICENSE) for more information.

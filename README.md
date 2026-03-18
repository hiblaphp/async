# Hibla Async

**Context-independent async/await for PHP without function coloring.**

`hiblaphp/async` brings `async` and `await` to PHP as plain functions built
on top of PHP 8.1 Fibers and the Hibla event loop. Unlike JavaScript, Python,
or C#, `await()` works in both fiber and non-fiber contexts — you write normal
functions and lift them into concurrency at the call site, not inside the
function definition.

[![Latest Release](https://img.shields.io/github/release/hiblaphp/async.svg?style=flat-square)](https://github.com/hiblaphp/async/releases)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

## Key Features

- **No function coloring:** `await()` works in both sync and async contexts.
  Write normal functions — make them concurrent at the call site with `async()`.
- **True cooperative concurrency:** `async()` schedules a Fiber on the event
  loop. Multiple `async()` blocks run concurrently, yielding to each other at
  every `await()` point.
- **Context-independent `await()`:** Inside a Fiber it suspends cooperatively.
  Outside a Fiber it falls back to blocking. The same function works in both
  contexts without any changes.
- **`CancellationToken` support:** Pass a token to `await()` and the promise
  is automatically tracked — cancelling the token cancels the awaited promise
  and triggers its `onCancel()` cleanup handlers.
- **Async-aware `sleep()`:** Suspends the current Fiber non-blocking when
  inside `async()`. Falls back to blocking sleep outside.

---

## Installation
```bash
composer require hiblaphp/async
```

**Requirements:**
- PHP 8.3+
- `hiblaphp/event-loop`
- `hiblaphp/promise`

---

## Fibers and Coroutines

PHP Fibers were introduced in PHP 8.1 as a first-class stackful coroutine
primitive. A **stackful coroutine** is a unit of execution that can be
suspended and resumed at any point in its call stack — including inside
deeply nested function calls. This is what separates Fibers from generators.

A generator can only suspend at the top-level `yield` inside the generator
function itself. A Fiber can suspend from anywhere in its call stack. When a
Fiber suspends, the entire call stack at that point — every function frame,
every local variable, every instruction pointer — is frozen and preserved.
When the Fiber is resumed, execution continues from exactly where it left off
as if nothing happened.

A coroutine is a function that can pause its execution mid-way, return
control to its caller, and later be resumed from exactly where it left off
with all its local state intact. Fibers are the stackful version of this
concept — the entire call stack is preserved, not just the immediate function
frame. A Fiber also has its own separate C-level stack, independent from the
main thread stack, which is what makes suspension at any depth possible.
```php
function fetchUserProfile(int $id): PromiseInterface
{
    return async(function () use ($id) {
        $user   = await(Http::get("/users/$id"));
        $avatar = await(Http::get("/avatars/$id"));

        return ['user' => $user, 'avatar' => $avatar];
    });
}

function buildDashboard(int $userId): PromiseInterface
{
    return async(function () use ($userId) {
        // Suspends here waiting for fetchUserProfile's fiber to complete
        $profile = await(fetchUserProfile($userId));

        // Suspends here waiting for the feed request
        $feed = await(Http::get("/feed/$userId"));

        return ['profile' => $profile, 'feed' => $feed];
    });
}

function loadPage(int $userId): PromiseInterface
{
    return async(function () use ($userId) {
        // Suspends here waiting for buildDashboard's fiber to complete
        $dashboard = await(buildDashboard($userId));

        // Suspends here waiting for the layout request
        $layout = await(Http::get("/layout/$userId"));

        return ['dashboard' => $dashboard, 'layout' => $layout];
    });
}

async(function () {
    // Each await() point is a potential suspension —
    // the call stack freezes at whichever await() is currently
    // pending and the event loop runs other fibers in between.
    //
    // Multiple pages can load concurrently because each loadPage()
    // call runs in its own fiber and suspends independently.
    [$page1, $page2, $page3] = await(Promise::all([
        loadPage(1),
        loadPage(2),
        loadPage(3),
    ]));

    echo $page1['dashboard']['profile']['user']->name;
});
```

`async()` creates a Fiber and schedules it on the event loop. When the
Fiber calls `await()` on a pending promise, it calls `Fiber::suspend()`
internally, freezing the entire call stack and returning control to the
event loop. When the promise resolves, `Loop::scheduleFiber()` queues the
Fiber to be resumed, and the event loop restores the full call stack and
continues execution from the suspension point.

### `await()` works outside Fibers too

You do not need to wrap every function in `async()` to use `await()`.
Because `await()` is context-independent, you can call it in a plain
synchronous function and it will block synchronously at the top level —
no Fiber required. This lets you write functions once and decide later
whether they need to be concurrent.
```php
// Plain synchronous function — await() blocks synchronously here
function getUserName(int $id): string
{
    $user = await(fetchUser($id));

    return $user->name;
}

// Works directly at the top level — blocks until resolved
$name = getUserName(1);

// Wrap in async() when you want it to run concurrently
$promise = async(fn() => getUserName(1));

// Use in combinators without any changes to getUserName()
$names = await(Promise::all([
    async(fn() => getUserName(1)),
    async(fn() => getUserName(2)),
    async(fn() => getUserName(3)),
]));

// Use with concurrency limiting
$names = await(Promise::concurrent(
    array_map(
        fn($id) => fn() => async(fn() => getUserName($id)),
        range(1, 100)
    ),
    concurrency: 10
));
```

The function itself never changes — the caller decides the execution
strategy. Write it once with `await()`, use it anywhere.

---

## 1. The Function Coloring Problem

In most async ecosystems — JavaScript, Python, C# — `async` and `await` are
keywords that live inside the function definition. The moment a function uses
`await`, it must be marked `async`, which changes its return type, which forces
every caller to also be `async`. The color spreads upward through the entire
call stack creating two incompatible worlds: sync code and async code.

Hibla solves this entirely. `await()` is just a regular PHP function that
checks its execution context at runtime. A function that uses `await()` has
no special marking, no changed return type, and no impact on its callers.
The caller decides whether to give it concurrency by wrapping it in `async()`.
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

The color (`async()`) lives at the call site, not inside the function. This
means you can write your entire application using normal functions with
`await()` and introduce concurrency selectively where you need it.

---

## 2. `async()` — Running Code Concurrently

`async()` wraps a callable in a PHP Fiber, schedules it on the event loop,
and returns a `Promise` that resolves with the callable's return value. The
callable does not run immediately — it is queued in the Fiber phase of the
next event loop iteration.
```php
use function Hibla\async;

$promise = async(function () {
    return 'hello from a fiber';
});

$promise->then(fn($value) => print($value)); // hello from a fiber
```

Multiple `async()` calls run concurrently. Each one gets its own Fiber and
yields to others at every `await()` point:
```php
$start = microtime(true);

async(function () {
    await(delay(1)); // async-aware delay — suspends this fiber, others continue
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
Loop::run();
echo microtime(true) - $start; // ~1.0
```

### One fiber runs at a time — never block inside `async()`

The event loop runs only one Fiber at a time. Fibers are cooperatively
scheduled — a Fiber runs until it explicitly suspends via `await()` or
`sleep()`, at which point the event loop picks up the next ready Fiber.
This cooperative model is what makes shared state safe without locks, but
it also means a Fiber that never suspends monopolizes the loop until it
finishes.

A **blocking call** inside a Fiber — PHP's native `sleep()`, a synchronous
database query, `file_get_contents()`, or any other call that blocks the
OS thread — stalls the **entire event loop** for its duration. No other
Fiber runs, no timers fire, no I/O is processed until the blocking call
returns.
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

The same rule applies to any I/O operation. Always use the async-aware
equivalents from the Hibla ecosystem — `Http::get()` instead of
`file_get_contents()`, `await(delay($n))` instead of `\sleep($n)`, stream
watchers via `hiblaphp/stream` instead of blocking `fread()`. If you need
to run genuinely blocking work or CPU-bound tasks, offload them to a
separate process via `hiblaphp/parallel` rather than running them inside
a Fiber.

### Exceptions inside `async()`

Any exception thrown inside an `async()` block rejects the returned promise.
If the promise is not handled, it follows the standard unhandled rejection
behavior — written to `STDERR` when garbage collected.
```php
$promise = async(function () {
    throw new \RuntimeException('Something went wrong');
});

$promise->catch(fn($e) => print($e->getMessage())); // Something went wrong
```

Always attach a `catch()` handler or `await()` the promise inside a
`try/catch` when you care about errors inside `async()` blocks:
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

### Performance note — avoid unnecessary wrapping

Each `async()` call creates a new PHP Fiber. Fibers are lightweight but they
are not free — each one allocates a C-level stack, a CPU instruction pointer,
and associated runtime state. Creating a Fiber just to immediately await a
single promise that already exists adds overhead with no benefit.

If a function already returns a promise — such as an HTTP client request or
any low-level promise API — `await()` it directly. There is no reason to
wrap it in a Fiber:
```php
// Unnecessary — allocates a full Fiber just to await one existing promise
$result = await(async(function () {
    return await(Http::get('/api/data'));
}));

// Correct — await the promise directly, no Fiber needed
$result = await(Http::get('/api/data'));
```

The same applies to any function that already returns a `PromiseInterface`.
Wrapping its return value in `async()` creates a Fiber whose only job is to
immediately suspend at a single `await()` and resolve — the Fiber adds
nothing to the execution and only wastes the allocation cost:
```php
// Unnecessary — fetchUser() already returns a Promise
$user = await(async(fn() => await(fetchUser(1))));

// Correct — await the promise directly
$user = await(fetchUser(1));
```

If you have a plain synchronous function that uses `await()` internally,
you also do not need to wrap it in `async()` to eliminate function coloring.
The function already works in both sync and async contexts as-is — `await()`
handles the context switch transparently. Wrapping it in `async()` just adds
an unnecessary Fiber on top:
```php
// A plain function using await() — no async() wrapper needed
function getUserName(int $id): string
{
    $user = await(fetchUser($id)); // blocks synchronously outside a Fiber,
                                   // suspends cooperatively inside one
    return $user->name;
}

// Unnecessary — getUserName() already works in both contexts
$name = await(async(fn() => getUserName(1)));

// Correct — call it directly, await() inside handles the context
$name = getUserName(1); // sync context — blocks

// Only wrap in async() when you specifically want concurrent execution
$promise = async(fn() => getUserName(1)); // justified — explicit concurrency
```

Use `async()` when you genuinely need a Fiber — when you need to await
multiple promises sequentially with logic in between, or when you want
a block of code to run concurrently as its own unit of work:
```php
// Good use — multiple awaits with logic between them,
// justified allocation of a Fiber
$promise = async(function () {
    $user    = await(fetchUser(1));
    $orders  = await(fetchOrders($user->id));
    $ratings = await(fetchRatings($user->id));

    return processData($user, $orders, $ratings);
});
```

The rule of thumb is: if you find yourself writing
`async(fn() => await($someExistingPromise))` or
`async(fn() => someSyncFunctionThatAlreadyUsesAwait())`, remove the
`async()` wrapper entirely. Reserve `async()` for blocks of work that
genuinely need their own Fiber context for concurrent execution.

---

## 3. `await()` — Suspending Until a Promise Settles

`await()` suspends the current Fiber until the given promise settles, then
returns the resolved value or throws the rejection reason.
```php
use function Hibla\await;

$user = await(fetchUser(1));
echo $user->name;
```

### Context-independent behavior

`await()` checks `Fiber::getCurrent()` at runtime and behaves accordingly:

- **Inside a Fiber** (`async()` block): suspends the Fiber cooperatively. The
  event loop continues running — other fibers, timers, and I/O all proceed
  while this Fiber waits.
- **Outside a Fiber** (top level or sync function): falls back to
  `Promise::wait()` and drives the event loop synchronously until the promise
  settles. Equivalent to calling `->wait()` directly.
```php
// Outside a Fiber — blocks synchronously (equivalent to ->wait())
$user = await(fetchUser(1));

// Inside a Fiber — suspends cooperatively
async(function () {
    $user = await(fetchUser(1)); // other work runs while waiting
    echo $user->name;
});
```

This context-independence is what eliminates function coloring. A function
that calls `await()` works correctly regardless of where it is called from —
it does not need to know or care whether it is inside a Fiber.

### Already-settled promises

If the promise passed to `await()` is already fulfilled at the time of the
call, `await()` returns the value immediately without suspending. If it is
already rejected, it throws immediately. If it is already cancelled, it throws
`CancelledException` immediately. No suspension, no event loop tick.
```php
$promise = Promise::resolved('immediate');

// Returns immediately — no suspension
$value = await($promise); // 'immediate'
```

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

If the promise is cancelled before or during the await, `await()` throws
`CancelledException`:
```php
async(function () use ($token) {
    try {
        $user = await(fetchUser(1), $token);
    } catch (\Hibla\Promise\Exceptions\CancelledException $e) {
        echo "Fetch was cancelled\n";
    }
});
```

### With `CancellationToken`

Pass a `CancellationToken` as the second argument to automatically track
the promise. If the token is cancelled while the Fiber is suspended, the
promise is cancelled and `CancelledException` is thrown at the `await()`
call site:
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

## 4. No Function Coloring in Practice

The full power of the no-coloring design becomes clear when you write library
code that uses `await()` internally. The same code works in every context
without any changes:
```php
// Library code — plain functions using await()
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

These are plain functions. Callers can use them in any of these ways
without any changes to the functions themselves:
```php
// 1. Synchronous — blocks at each call
$data = getUserWithOrders(1);

// 2. Single async task — runs in a fiber, non-blocking
$promise = async(fn() => getUserWithOrders(1));

// 3. Concurrent — multiple users fetched in parallel
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

The functions never changed. The concurrency strategy is entirely decided
by the caller.

---

## 5. `asyncFn()` — Wrapping a Callable

`asyncFn()` wraps a callable so that every call to it automatically runs
inside `async()` and returns a `Promise`. Useful when you want to convert
an existing function into a reusable async factory without changing the
original function.

Note that `asyncFn()` creates a new Fiber on every call — the same
performance considerations from the "avoid unnecessary wrapping" section
apply. Only use it when the wrapped function genuinely needs its own Fiber
context for concurrent execution:
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

// Each call returns a Promise and runs in its own Fiber —
// primary use case: passing to Promise::map() or Promise::concurrent()
await(Promise::map($records, $asyncProcess, concurrency: 10));

// Equivalent to:
await(Promise::concurrent(
    array_map(fn($record) => fn() => async(fn() => processRecord($record)), $records),
    concurrency: 10
));
```

---

## 6. `sleep()` — Async-Aware Pause

The `sleep()` function from `hiblaphp/async` is an async-aware replacement
for PHP's native `sleep()`. It accepts fractional seconds — `sleep(0.5)` for
500ms, `sleep(1.5)` for 1.5 seconds — unlike PHP's native `sleep()` which
only accepts integers.

- **Inside a Fiber:** suspends the current Fiber non-blocking. The event loop
  continues — other fibers, timers, and I/O run while this Fiber waits.
- **Outside a Fiber:** blocks the entire script, identical to PHP's native
  `sleep()`.
```php
use function Hibla\sleep;

// Inside async() — non-blocking, other fibers continue
async(function () {
    echo "Task 1 start\n";
    sleep(2);
    echo "Task 1 done\n"; // runs after 2 seconds
});

async(function () {
    echo "Task 2 start\n";
    sleep(1);
    echo "Task 2 done\n"; // runs after 1 second — before Task 1
});

// Output order:
// Task 1 start
// Task 2 start
// Task 2 done  (after ~1 second)
// Task 1 done  (after ~2 seconds)
// Total time: ~2 seconds, not 3
```

> **Important:** Always import `Hibla\sleep` explicitly. PHP's native
> `sleep()` and Hibla's `sleep()` have the same name — if you forget the
> import you will silently call PHP's native blocking `sleep()` instead,
> stalling the entire event loop with no error or warning:
>
> ```php
> use function Hibla\sleep; // required — do not omit
>
> async(function () {
>     sleep(1); // Hibla's sleep — correct
> });
>
> // Without the import, this calls PHP's native sleep() — wrong
> async(function () {
>     \sleep(1); // stalls the entire loop
> });
> ```

---

## 7. `inFiber()` — Context Detection

`inFiber()` returns `true` if the current code is executing inside a PHP
Fiber. Useful for writing code that needs to behave differently depending
on whether it is in an async context:
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

In most cases you will not need this — `await()` already handles both
contexts automatically. `inFiber()` is primarily useful when you want to
select between fundamentally different implementations rather than just
different blocking behaviors.

---

## 8. Combining with Promise Combinators

`async()` returns a standard `Promise` so it composes naturally with all
of `hiblaphp/promise`'s collection and concurrency methods:

### Running tasks concurrently with `Promise::all()`
```php
use function Hibla\async;
use function Hibla\await;
use Hibla\Promise\Promise;

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
use Hibla\Cancellation\CancellationTokenSource;

$cts = new CancellationTokenSource();

try {
    $result = await(Promise::timeout(
        async(function () use ($cts) {
            return await(slowOperation(), $cts->token);
        }),
        seconds: 5.0
    ));
} catch (\Hibla\Promise\Exceptions\TimeoutException $e) {
    // timeout fired — slowOperation()'s onCancel() was triggered
    // via the token, freeing any resources it held
    echo "Operation timed out\n";
}
```

---

## 9. Cancellation inside `async()`

Pass a `CancellationToken` to `await()` calls inside `async()` blocks to
support external cancellation of the entire workflow. When the token is
cancelled, the current `await()` throws `CancelledException` and the Fiber
unwinds naturally through any `catch` or `finally` blocks.

Use `finally` inside `async()` to guarantee cleanup runs whether the
workflow completes normally, throws, or is cancelled:
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
        // Runs regardless of outcome — normal completion, exception, or cancellation
        $connection->close();
    }
});

// Cancel from anywhere — the next await() in the workflow throws
Loop::addTimer(2.0, fn() => $cts->cancel());

$result = await($workflow);
```

If the token is already cancelled before the first `await()` inside the
fiber runs, the first `await()` call throws `CancelledException` immediately
without suspending.

### Automatic resource cleanup without `track()`

When you pass a token to `await()`, the promise is automatically tracked by
the token — you do not need to call `token->track($promise)` manually. This
is particularly useful when awaiting promises that already have `onCancel()`
handlers registered internally, such as HTTP requests from
`hiblaphp/http-client`. The token triggers the promise's own `onCancel()`
cleanup without any extra wiring at the call site.
```php
$cts = new CancellationTokenSource(5.0); // 5 second timeout

$workflow = async(function () use ($cts) {
    // Http::get() returns a promise with an onCancel() handler that
    // aborts the underlying curl request when cancelled.
    // Passing $cts->token to await() is enough — no track() needed.
    // When the token fires, the promise is cancelled and its internal
    // onCancel() fires, aborting the HTTP request immediately.
    $response = await(Http::get('https://api.example.com/users'), $cts->token);
    $data     = await(Http::get('https://api.example.com/orders'), $cts->token);

    return compact('response', 'data');
});
```

Compare this to manually tracking:
```php
// Manual tracking — more verbose, same result
$request = Http::get('https://api.example.com/users');
$cts->token->track($request);
$response = await($request);
```

Passing the token directly to `await()` is the preferred pattern when you
are already inside an `async()` block — it is more concise and keeps the
cancellation wiring at the `await()` call site where the suspension happens,
making the code easier to follow.

---

## 10. Testing Async Code

Because `await()` falls back to blocking synchronously outside a Fiber, you
can test async code directly without any special test runner setup, event loop
runner, or test helpers. Just call `await()` at the test level and it drives
the loop until the promise settles:
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

This is one of the strongest practical advantages of context-independent
`await()` — the same code that runs non-blocking in production runs blocking
in tests, with no adaptation required.

---

## 11. Comparison with JavaScript async/await

| | JavaScript | Hibla |
|---|---|---|
| `await` usable in sync functions | No — syntax error | Yes — falls back to blocking |
| Function coloring | Yes — spreads upward | No — color lives at call site |
| Marking a function async | Required (`async function`) | Not required |
| Return type change | Yes — always returns `Promise` | No — return type unchanged |
| Concurrency primitive | `async function` | `async(fn() => ...)` at call site |
| Context detection | Not available | `inFiber()` |
| Testing async code | Requires async test runner | Plain `await()` — no setup needed |

The fundamental difference is that JavaScript's `await` is a compile-time
grammar rule — the parser enforces it at the syntax level. Hibla's `await()`
is a runtime function call that checks `Fiber::getCurrent()`. This single
difference is what eliminates function coloring entirely.

---

## API Reference

### Functions

| Function | Description |
|---|---|
| `async(callable $function): PromiseInterface` | Wrap a callable in a Fiber and schedule it on the event loop. Returns a Promise that resolves with the callable's return value. The callable accepts no parameters. |
| `await(PromiseInterface $promise, ?CancellationToken $token): mixed` | Suspend the current Fiber until the promise settles (inside Fiber), or block synchronously (outside Fiber). Returns immediately if the promise is already settled. Automatically tracks the promise on the token if provided. Throws on rejection or cancellation. |
| `asyncFn(callable $function): callable` | Wrap a callable so every call runs inside `async()` and returns a Promise. Creates a new Fiber per call. |
| `sleep(float $seconds): void` | Suspend the current Fiber non-blocking (inside Fiber), or block synchronously (outside Fiber). Accepts fractional seconds. |
| `inFiber(): bool` | Returns true if currently executing inside a PHP Fiber. |

---

## Development

### Running Tests
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

- **API Design:** Inspired by JavaScript's `async`/`await` syntax and the
  function coloring problem described by Bob Nystrom in
  ["What Color is Your Function?"](https://journal.stuffwithstuff.com/2015/02/01/what-color-is-your-function/).
  Hibla's context-independent `await()` is a direct solution to the problem
  that language described.
- **Fiber Scheduling:** Powered by [hiblaphp/event-loop](https://github.com/hiblaphp/event-loop).
- **Promise Integration:** Built on [hiblaphp/promise](https://github.com/hiblaphp/promise).

---

## License

MIT License. See [LICENSE](./LICENSE) for more information.
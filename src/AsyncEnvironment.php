<?php

declare(strict_types=1);

namespace Hibla\Async;

/**
 * Manages global runtime configurations for the Hibla Async execution engine.
 *
 * This class allows developers and framework builders to configure the behavior
 * of the async/await primitives, primarily focusing on execution safety and
 * preventing silent blocking operations in high-concurrency environments.
 */
final class AsyncEnvironment
{
    private static bool $strictAwait = false;

    /**
     * Enable strict validation mode for all `await()` operations.
     *
     * In strict mode, calling `await()` outside of an active Fiber context
     * (such as on `{main}` or within a standard Event Loop tick callback)
     * will immediately throw an {@see InvalidContextException} instead of
     * falling back to cooperative blocking.
     *
     * Why enable this?
     * Highly recommended for high-concurrency network engines (like HTTP servers)
     * to prevent "context loss" bugs. It instantly exposes programming errors
     * where a developer accidentally calls `await()` inside a standard loop callback,
     * which would otherwise silently block the entire thread and degrade server throughput.
     */
    public static function enableStrictAwait(): void
    {
        self::$strictAwait = true;
    }

    /**
     * Disable strict validation mode for `await()` operations.
     *
     * This restores the cooperative blocking fallback behavior. If `await()`
     * is called outside of an active Fiber context, it will safely drive
     * the Event Loop recursively until the underlying Promise settles, then
     * return the resolved value synchronously.
     *
     * Why use this?
     * This is the default mode. It is highly useful for simple CLI scripts,
     * rapid prototyping, and unit testing where executing the loop synchronously
     * at the top-level is convenient and safe.
     */
    public static function disableStrictAwait(): void
    {
        self::$strictAwait = false;
    }

    /**
     * Check if strict context validation is currently enabled for `await()`.
     *
     * @return bool True if strict mode is active and throws on context boundaries;
     *              false if cooperative blocking fallback is allowed.
     */
    public static function isStrictAwaitEnabled(): bool
    {
        return self::$strictAwait;
    }
}
# PHP Shutdown Terminator

A lightweight PHP library for registering and executing handlers at the end of script execution, with built-in memory reservation for fatal error recovery.

## :bulb: Key Principles

- **Automatic Memory Reservation**: Pre-allocates memory to ensure handlers execute even during fatal out-of-memory errors
- **Priority-Based Execution**: Handlers are sorted and executed in order of their assigned priority
- **Graceful Error Handling**: Exceptions in handlers are caught and logged without disrupting other handlers
- **Zero Dependencies**: Works standalone or integrates seamlessly with Tracy Debugger
- **Simple Interface**: Single method interface for handler implementation
- **Works with `die`/`exit`**: Handlers are invoked even when script terminates via `die()` or `exit()`

## :building_construction: Architecture & Components

The package consists of four main components working together:

```
+-------------------------------------------------------------------+
|                           Terminator                              |
|         (Static orchestrator managing handler lifecycle)          |
|                                                                   |
|  +-------------------------------------------------------------+  |
|  |      Reserved Memory Buffer (100KB + custom allocations)    |  |
|  +-------------------------------------------------------------+  |
|                                                                   |
|  +-------------------------------------------------------------+  |
|  |  Handler Registry: array<RegisteredHandler>                 |  |
|  |                                                             |  |
|  |  +--------------------+    +--------------------+           |  |
|  |  | RegisteredHandler  |    | RegisteredHandler  |   ...     |  |
|  |  | - handler          |    | - handler          |           |  |
|  |  | - priority: 1      |    | - priority: 5      |           |  |
|  |  +---------+----------+    +---------+----------+           |  |
|  |            |                         |                      |  |
|  |            v                         v                      |  |
|  |  +--------------------+    +--------------------+           |  |
|  |  | TerminatorHandler  |    | TerminatorHandler  |           |  |
|  |  |    (interface)     |    |    (interface)     |           |  |
|  |  +--------------------+    +--------------------+           |  |
|  +-------------------------------------------------------------+  |
+-------------------------------------------------------------------+

                        Shutdown Sequence
                               |
                               v
                  +----------------------+
                  |   PHP Script Ends    |
                  |  (exit/die/natural)  |
                  +-----------+----------+
                              |
                              v
                  +----------------------+
                  |  shutdownHandler()   |
                  |    called by PHP     |
                  +-----------+----------+
                              |
                              v
                  +----------------------+
                  |  Sort handlers by    |
                  |    priority (ASC)    |
                  +-----------+----------+
                              |
                              v
                  +----------------------+
                  | Execute each handler |
                  |  with error trapping |
                  +----------------------+
```

### :gear: Component Overview

| Component | Type | Description |
|-----------|------|-------------|
| `Terminator` | Static Class | Main orchestrator that manages the shutdown handler registry, memory reservation, and execution lifecycle |
| `TerminatorHandler` | Interface | Contract that all handler classes must implement; defines the `processTerminatorHandler()` method |
| `RegisteredHandler` | Value Object | Internal wrapper that pairs a handler instance with its execution priority |
| `TerminatorShutdownHandlerException` | Exception | Custom exception thrown when handler execution fails; used for Tracy Debugger logging |

## :bulb: How It Works

### Memory Reservation Strategy

When the first handler is registered, Terminator immediately:

1. Reserves **100 KB** of memory by allocating a string buffer
2. Registers PHP's native `register_shutdown_function()` to invoke `shutdownHandler()`

This pre-allocated memory acts as a safety buffer. If PHP runs out of memory during normal execution, the shutdown handler can still execute because the reserved memory is released and becomes available.

Additional memory can be reserved per-handler for complex cleanup operations.

### Shutdown Execution Flow

1. **Trigger**: PHP shutdown event occurs (script end, `die()`, `exit()`, or fatal error)
2. **Guard Check**: Ensures handlers run exactly once via `$hasShutdown` flag
3. **User Abort Ignored**: Calls `ignore_user_abort(true)` to continue even if client disconnects
4. **Priority Sort**: Handlers are sorted ascending by priority (lower numbers execute first)
5. **Sequential Execution**: Each handler's `processTerminatorHandler()` is invoked
6. **Error Isolation**: Exceptions are caught, logged (via Tracy if available), and execution continues

### Handler States

The `$hasShutdown` flag tracks three states:

| State | Value | Meaning |
|-------|-------|---------|
| Not initialized | `null` | No handlers registered yet |
| Ready | `false` | Handlers registered, waiting for shutdown |
| Completed | `true` | Shutdown function has been executed |

## :package: Installation

It's best to use [Composer](https://getcomposer.org) for installation, and you can also find the package on
[Packagist](https://packagist.org/packages/baraja-core/shutdown-terminator) and
[GitHub](https://github.com/baraja-core/shutdown-terminator).

To install, simply use the command:

```shell
$ composer require baraja-core/shutdown-terminator
```

You can use the package manually by creating an instance of the internal classes, or register a DIC extension to link the services directly to the Nette Framework.

### Requirements

- PHP 8.0 or higher
- No required dependencies (optional Tracy Debugger integration)

## :rocket: Basic Usage

### Implementing a Handler

Create a class that implements the `TerminatorHandler` interface:

```php
<?php

use Baraja\ShutdownTerminator\Terminator;
use Baraja\ShutdownTerminator\TerminatorHandler;

class MyLogger implements TerminatorHandler
{
    public function __construct()
    {
        // Register this service to Terminator
        Terminator::addHandler($this);
    }

    public function processTerminatorHandler(): void
    {
        // This logic will be called when the script terminates
        file_put_contents('/var/log/app.log', 'Script finished at ' . date('c'), FILE_APPEND);
    }
}
```

### Registering with Priority

Lower priority numbers execute first:

```php
// Execute first (priority 1)
Terminator::addHandler($criticalHandler, priority: 1);

// Execute second (priority 5, default)
Terminator::addHandler($normalHandler);

// Execute last (priority 10)
Terminator::addHandler($cleanupHandler, priority: 10);
```

### Reserving Additional Memory

For memory-intensive cleanup operations, reserve extra memory:

```php
// Reserve additional 50 KB for this handler
Terminator::addHandler($heavyHandler, reservedMemoryKB: 50);

// Combine with priority
Terminator::addHandler($heavyHandler, reservedMemoryKB: 100, priority: 1);
```

### Checking Terminator State

Verify if the Terminator is ready and waiting:

```php
if (Terminator::isReady()) {
    // Handlers are registered and waiting for shutdown
}
```

## :wrench: Configuration Options

### `addHandler()` Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$handler` | `TerminatorHandler` | required | The handler instance to register |
| `$reservedMemoryKB` | `int` | `0` | Additional memory to reserve in kilobytes |
| `$priority` | `int` | `5` | Execution order (lower = earlier); minimum is `0` |

## :mag: Advanced Examples

### Database Connection Cleanup

```php
class DatabaseCleanup implements TerminatorHandler
{
    public function __construct(
        private PDO $connection,
    ) {
        Terminator::addHandler($this, priority: 10); // Run last
    }

    public function processTerminatorHandler(): void
    {
        // Commit any pending transactions
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }
}
```

### Session Data Persistence

```php
class SessionPersister implements TerminatorHandler
{
    public function __construct(
        private SessionManager $session,
    ) {
        Terminator::addHandler($this, priority: 1); // Run first
    }

    public function processTerminatorHandler(): void
    {
        $this->session->save();
    }
}
```

### Metrics Collection

```php
class MetricsCollector implements TerminatorHandler
{
    private float $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
        Terminator::addHandler($this, reservedMemoryKB: 10);
    }

    public function processTerminatorHandler(): void
    {
        $duration = microtime(true) - $this->startTime;
        $memory = memory_get_peak_usage(true);

        // Send metrics to monitoring service
        $this->sendMetrics([
            'duration_ms' => $duration * 1000,
            'peak_memory_bytes' => $memory,
        ]);
    }

    private function sendMetrics(array $data): void
    {
        // Implementation
    }
}
```

### Error State Reporting

```php
class ErrorReporter implements TerminatorHandler
{
    private array $errors = [];

    public function __construct()
    {
        Terminator::addHandler($this, reservedMemoryKB: 50, priority: 2);
        set_error_handler([$this, 'captureError']);
    }

    public function captureError(int $errno, string $errstr): bool
    {
        $this->errors[] = ['code' => $errno, 'message' => $errstr];
        return false;
    }

    public function processTerminatorHandler(): void
    {
        if (!empty($this->errors)) {
            // Send error report to external service
            $this->sendErrorReport($this->errors);
        }
    }

    private function sendErrorReport(array $errors): void
    {
        // Implementation
    }
}
```

## :shield: Error Handling

### Handler Exceptions

If a handler throws an exception during execution:

1. **CLI Environment**: Error message, file, and line are printed to stdout
2. **Tracy Debugger**: Exception is logged via `Debugger::log()` as `ILogger::EXCEPTION`
3. **Other Handlers**: Continue executing regardless of previous failures

```php
class FaultyHandler implements TerminatorHandler
{
    public function processTerminatorHandler(): void
    {
        throw new RuntimeException('Something went wrong');
        // Other handlers will still execute
    }
}
```

### Tracy Debugger Integration

When Tracy Debugger is available, handler exceptions are automatically wrapped in `TerminatorShutdownHandlerException` and logged:

```php
// Logged exception message format:
"An error occurred while processing the shutdown function: {original message}"
```

## :bulb: Best Practices

1. **Keep handlers lightweight**: Shutdown handlers should be fast and focused
2. **Use appropriate priorities**: Critical operations (session save) should have lower priority numbers
3. **Reserve memory conservatively**: Only allocate what you need
4. **Handle your own exceptions**: While Terminator catches exceptions, handlers should manage their own error states
5. **Avoid infinite loops**: Handlers are protected from being called twice, but internal loops can still hang
6. **Test in CLI**: The CLI output helps debug handler issues during development

## :warning: Caveats

- Handlers run synchronously in a single thread
- The `ignore_user_abort(true)` call means handlers complete even if the client disconnects
- Memory reservation happens immediately when handler is registered, not at shutdown
- Fatal errors like `E_ERROR` will trigger shutdown, but parse errors (`E_PARSE`) occur before registration

## :bust_in_silhouette: Author

**Jan Barášek** - [https://baraja.cz](https://baraja.cz)

## :page_facing_up: License

`baraja-core/shutdown-terminator` is licensed under the MIT license. See the [LICENSE](https://github.com/baraja-core/shutdown-terminator/blob/master/LICENSE) file for more details.

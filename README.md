PHP Shutdown Terminator
=======================

Registers handlers that run at the end of the request.

Idea
----

When processing more complex PHP applications, we often need to perform some operations at the end of the script run. Most often saving states to the database, sending logs and processing error states.

This package provides a simple interface to accomplish this.

The Terminator automatically reserves the operating memory so that it is possible to process handlers even in the event that the script is forcibly terminated due to memory exhaustion. Handlers call each other when you use `die` or `exit` in your code.

Installation
---------

```shell
$ composer require baraja-core/shutdown-terminator
```

How to use
----------

In your class for which you want to call a method after the script exits, simply implement the `TerminatorHandler` interface and register the handler:

```php
class MyLogger implements \Baraja\ShutdownTerminator\TerminatorHandler
{
    public function __construct()
    {
        // register this service to Terminator
        Terminator::addHandler($this);
    }

    public function processTerminatorHandler(): void
    {
        // this logic will be called by Terminator.
    }
}
```

Configuration
-------------

The `addHandler()` method supports handler registration, for which you can add your own priority and reserved RAM limit.

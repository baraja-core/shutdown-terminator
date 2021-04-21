<?php

declare(strict_types=1);

namespace Baraja\ShutdownTerminator;


use Tracy\Debugger;
use Tracy\ILogger;

final class Terminator
{
	/** @var array<int, RegisteredHandler> */
	private static array $handlers = [];

	/**
	 * Has ShutdownHandlerTerminator been registered to process handlers?
	 *
	 * null => has never been registered
	 * true => is ready and waiting for shutdown function
	 * false => shutdown function was called
	 */
	private static ?bool $hasShutdown = null;

	/** @var string reserved memory; also prevents double rendering */
	private static string $reserved = '';


	public static function addHandler(TerminatorHandler $handler, int $reservedMemoryKB = 0, int $priority = 5): void
	{
		// 1. Register native shutdown function and allocate reserved memory for fatal errors
		if (self::$hasShutdown === null) {
			self::$reserved = str_repeat('t', 100000);
			register_shutdown_function([self::class, 'shutdownHandler']);
			self::$hasShutdown = false;
		}
		// Debugger::log(new \RuntimeException('aa'));
		// 2. User can reserve additional memory for custom handler
		if ($reservedMemoryKB > 0) {
			self::$reserved .= str_repeat('t', $reservedMemoryKB * 1000);
		}
		self::$handlers[] = new RegisteredHandler($handler, $priority);
	}


	/** @internal */
	public static function shutdownHandler(): void
	{
		ignore_user_abort(true);
		if (self::$hasShutdown === true) {
			return;
		}
		self::$hasShutdown = true;
		usort(
			self::$handlers,
			static function (RegisteredHandler $a, RegisteredHandler $b): int {
				return $a->getPriority() > $b->getPriority() ? 1 : -1;
			}
		);
		foreach (self::$handlers as $handlerInfo) {
			try {
				$handlerInfo->getHandler()->processTerminatorHandler();
			} catch (\Throwable $e) {
				if (PHP_SAPI === 'cli') {
					echo "\n\n" . 'Shutdown handler error:';
					echo "\n" . $e->getMessage();
					echo "\n\n" . 'On file: ' . $e->getFile() . ':' . $e->getLine();
					echo "\n\n";
				}
				if (class_exists('\Tracy\Debugger')) {
					Debugger::log(
						new TerminatorShutdownHandlerException(
							'An error occurred while processing the shutdown function: ' . $e->getMessage(),
							$e->getCode(),
							$e
						),
						ILogger::EXCEPTION
					);
				}
			}
		}
	}


	public static function isReady(): bool
	{
		return self::$hasShutdown === false;
	}
}

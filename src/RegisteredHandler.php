<?php

declare(strict_types=1);

namespace Baraja\ShutdownTerminator;


final class RegisteredHandler
{
	public function __construct(
		private TerminatorHandler $handler,
		private int $priority,
	) {
		if ($this->priority < 0) {
			$this->priority = 0;
		}
	}


	public function getHandler(): TerminatorHandler
	{
		return $this->handler;
	}


	public function getPriority(): int
	{
		return $this->priority;
	}
}

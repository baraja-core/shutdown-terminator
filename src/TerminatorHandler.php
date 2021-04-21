<?php

declare(strict_types=1);

namespace Baraja\ShutdownTerminator;


interface TerminatorHandler
{
	public function processTerminatorHandler(): void;
}

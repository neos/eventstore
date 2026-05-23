<?php

declare(strict_types=1);

namespace Neos\EventStore\Tests\Integration;

use Psr\Clock\ClockInterface;

/**
 * Clock implementation for tests
 * This is a mutable class in order to allow to adjust the behaviour during runtime for testing purposes
 */
final class EventStoreFakeClock implements ClockInterface
{
    private static ?self $instance = null;

    private function __construct(
        private ?\DateTimeImmutable $now = null
    ) {
    }

    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    public static function setNow(\DateTimeImmutable $now): void
    {
        self::get()->now = $now;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now ?? new \DateTimeImmutable();
    }
}

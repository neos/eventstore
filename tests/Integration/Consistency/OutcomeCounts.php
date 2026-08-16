<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

/**
 * How often something was attempted, and how those attempts ended
 */
final class OutcomeCounts
{
    private function __construct(
        private int $successes,
        private int $rejections,
        private int $errors,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0, 0);
    }

    public function record(Outcome $outcome): void
    {
        match ($outcome) {
            Outcome::SUCCESS => $this->successes++,
            Outcome::REJECTED => $this->rejections++,
            Outcome::UNEXPECTED_ERROR => $this->errors++,
        };
    }

    public function total(): int
    {
        return $this->successes + $this->rejections + $this->errors;
    }

    public function successes(): int
    {
        return $this->successes;
    }

    public function rejections(): int
    {
        return $this->rejections;
    }

    public function errors(): int
    {
        return $this->errors;
    }
}

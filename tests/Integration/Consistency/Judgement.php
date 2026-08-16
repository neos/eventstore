<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

/**
 * What the generator was able to prove about an attempt before it ran – and why
 *
 * The reason is carried along because a violated verdict is only actionable together with the knowledge
 * it was derived from ("expected version 3 but the stream is known to be at version 7 or higher").
 */
final readonly class Judgement
{
    private function __construct(
        public Verdict $verdict,
        public string $reason,
    ) {
    }

    public static function create(Verdict $verdict, string $reason): self
    {
        return new self($verdict, $reason);
    }

    public static function mustFail(string $reason): self
    {
        return new self(Verdict::MUST_FAIL, $reason);
    }

    public static function mustSucceed(string $reason): self
    {
        return new self(Verdict::MUST_SUCCEED, $reason);
    }

    public static function undecidable(string $reason): self
    {
        return new self(Verdict::UNDECIDABLE, $reason);
    }
}

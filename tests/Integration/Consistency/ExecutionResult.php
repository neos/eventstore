<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Exception\ConcurrencyException;
use Neos\EventStore\Model\Event\SequenceNumber;

/**
 * What the store did when an attempt was executed
 *
 * Errors are recorded as class name and message rather than as the exception itself, because the op log
 * survives the process that produced it.
 */
final readonly class ExecutionResult
{
    private function __construct(
        public Outcome $outcome,
        public ?SequenceNumber $highestCommittedSequenceNumber,
        public CommittedVersions $committedVersions,
        public ?string $errorClass,
        public ?string $errorMessage,
    ) {
    }

    public static function success(SequenceNumber $highestCommittedSequenceNumber, CommittedVersions $committedVersions): self
    {
        return new self(Outcome::SUCCESS, $highestCommittedSequenceNumber, $committedVersions, null, null);
    }

    /**
     * A legitimate outcome – unless the verdict was {@see Verdict::MUST_SUCCEED}
     */
    public static function rejected(ConcurrencyException $exception): self
    {
        return new self(Outcome::REJECTED, null, CommittedVersions::none(), $exception::class, $exception->getMessage());
    }

    /**
     * Anything the store threw that is not a {@see ConcurrencyException} – always a violation
     */
    public static function unexpectedError(\Throwable $exception): self
    {
        return new self(Outcome::UNEXPECTED_ERROR, null, CommittedVersions::none(), $exception::class, $exception->getMessage());
    }

    public static function fromJson(Json $json): self
    {
        $highestCommittedSequenceNumber = $json->nullableInteger('highestCommittedSequenceNumber');
        return new self(
            Outcome::from($json->string('outcome')),
            $highestCommittedSequenceNumber === null ? null : SequenceNumber::fromInteger($highestCommittedSequenceNumber),
            CommittedVersions::fromJsonList($json->objectList('committedVersions')),
            $json->nullableString('errorClass'),
            $json->nullableString('errorMessage'),
        );
    }

    /**
     * @return array{outcome: string, highestCommittedSequenceNumber: int|null, committedVersions: list<array{stream: string, version: int}>, errorClass: string|null, errorMessage: string|null}
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'highestCommittedSequenceNumber' => $this->highestCommittedSequenceNumber?->value,
            'committedVersions' => $this->committedVersions->toArray(),
            'errorClass' => $this->errorClass,
            'errorMessage' => $this->errorMessage,
        ];
    }

    public function toDebugString(): string
    {
        if ($this->errorClass === null) {
            return $this->outcome->value;
        }
        return sprintf('%s (%s: %s)', $this->outcome->value, $this->errorClass, $this->errorMessage ?? '');
    }
}

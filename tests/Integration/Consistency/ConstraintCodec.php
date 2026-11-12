<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventStream\ExpectedNoStream;
use Neos\EventStore\Model\EventStream\ExpectedStreamConstraints;
use Neos\EventStore\Model\EventStream\ExpectedStreamExists;
use Neos\EventStore\Model\EventStream\ExpectedStreamVersion;

/**
 * Translates expected stream constraints to and from the JSON of the op log
 *
 * Constraints are logged structurally rather than as debug strings, because the validator has to
 * re-evaluate them against the state the store was in when the commit landed
 * {@see ConsistencyValidator::checkConstraintsHeldWhenTheCommitLanded()}.
 */
final class ConstraintCodec
{
    private const EXPECTATION_VERSION = 'version';
    private const EXPECTATION_NO_STREAM = 'no_stream';
    private const EXPECTATION_EXISTS = 'exists';

    /**
     * @return list<array{stream: string, expectation: string, version: int|null}>
     */
    public static function toArray(ExpectedStreamConstraints $constraints): array
    {
        $items = [];
        foreach ($constraints as $constraint) {
            $items[] = [
                'stream' => $constraint->streamName->value,
                'expectation' => match (true) {
                    $constraint instanceof ExpectedStreamVersion => self::EXPECTATION_VERSION,
                    $constraint instanceof ExpectedNoStream => self::EXPECTATION_NO_STREAM,
                    $constraint instanceof ExpectedStreamExists => self::EXPECTATION_EXISTS,
                },
                'version' => $constraint instanceof ExpectedStreamVersion ? $constraint->expectedVersion->value : null,
            ];
        }
        return $items;
    }

    /**
     * @param list<Json> $jsonList
     */
    public static function fromJsonList(array $jsonList): ExpectedStreamConstraints
    {
        $constraints = [];
        foreach ($jsonList as $json) {
            $streamName = StreamName::fromString($json->string('stream'));
            $expectation = $json->string('expectation');
            $constraints[] = match ($expectation) {
                self::EXPECTATION_VERSION => ExpectedStreamVersion::create($streamName, Version::fromInteger($json->integer('version'))),
                self::EXPECTATION_NO_STREAM => ExpectedNoStream::create($streamName),
                self::EXPECTATION_EXISTS => ExpectedStreamExists::create($streamName),
                default => throw new \RuntimeException(sprintf('Unsupported constraint expectation "%s"', $expectation), 1781013030),
            };
        }
        return ExpectedStreamConstraints::create(...$constraints);
    }
}

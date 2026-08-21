<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\EventStream\ExpectedStreamConstraints;

/**
 * One executed attempt: what the process intended, and what the store did about it
 *
 * The op log is what makes the oracle two-directional. Validating from the event payloads alone can only
 * ever inspect events that *were* written, which is structurally blind to a commit that reported success
 * but wrote nothing, and to a commit that threw but wrote anyway.
 */
final readonly class OpLogEntry
{
    private function __construct(
        public string $runId,
        public string $commitId,
        public int $pid,
        public int $dataset,
        public AttemptShape $shape,
        public Judgement $judgement,
        public CommitApi $commitApi,
        public Segments $segments,
        public ExpectedStreamConstraints $constraints,
        public EventIds $eventIds,
        public int $jitterMicroseconds,
        public ExecutionResult $result,
    ) {
    }

    public static function create(
        RunManifest $manifest,
        Attempt $attempt,
        int $pid,
        int $dataset,
        int $jitterMicroseconds,
        ExecutionResult $result,
    ): self {
        return new self(
            runId: $manifest->runId,
            commitId: $attempt->commitId,
            pid: $pid,
            dataset: $dataset,
            shape: $attempt->shape,
            judgement: $attempt->judgement,
            commitApi: $attempt->commitApi(),
            segments: $attempt->segments(),
            constraints: $attempt->constraints(),
            eventIds: $attempt->eventIds(),
            jitterMicroseconds: $jitterMicroseconds,
            result: $result,
        );
    }

    public function toJson(): string
    {
        return json_encode([
            'runId' => $this->runId,
            'commitId' => $this->commitId,
            'pid' => $this->pid,
            'dataset' => $this->dataset,
            'shape' => $this->shape->value,
            'verdict' => $this->judgement->verdict->value,
            'verdictReason' => $this->judgement->reason,
            'commitApi' => $this->commitApi->value,
            'segments' => $this->segments->toArray(),
            'constraints' => ConstraintCodec::toArray($this->constraints),
            'eventIds' => $this->eventIds->toStringList(),
            'jitterMicroseconds' => $this->jitterMicroseconds,
            'result' => $this->result->toArray(),
        ], JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        $data = Json::decode($json);
        return new self(
            runId: $data->string('runId'),
            commitId: $data->string('commitId'),
            pid: $data->integer('pid'),
            dataset: $data->integer('dataset'),
            shape: AttemptShape::from($data->string('shape')),
            judgement: Judgement::create(Verdict::from($data->string('verdict')), $data->string('verdictReason')),
            commitApi: CommitApi::from($data->string('commitApi')),
            segments: Segments::fromJsonList($data->objectList('segments')),
            constraints: ConstraintCodec::fromJsonList($data->objectList('constraints')),
            eventIds: EventIds::fromStrings($data->stringList('eventIds')),
            jitterMicroseconds: $data->integer('jitterMicroseconds'),
            result: ExecutionResult::fromJson($data->object('result')),
        );
    }

    /**
     * A compact one-line rendering used in the violation digest
     */
    public function toDebugString(): string
    {
        return sprintf(
            '%s [%s via %s] writes %s, expects %s, %s: %s',
            $this->commitId,
            $this->shape->value,
            $this->commitApi->value,
            $this->segments->toDebugString(),
            $this->constraints->toDebugString(),
            $this->judgement->verdict->value,
            $this->result->toDebugString(),
        );
    }
}

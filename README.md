# General purpose event store

This package provides interfaces and helpers to create Event-Sourced systems with PHP

## Scope

In contrast to [Neos.EventSourcing](https://github.com/neos/Neos.EventSourcing) this package provides merely the low-level
building blocks, has just a couple of dependencies and is less opinionated.

> [!NOTE]
> This package mostly contains interfaces and implementations of Data Transfer Objects. To actually persist events,
> a corresponding adapter package is required, for example [neos/eventstore-doctrineadapter](https://github.com/neos/eventstore-doctrineadapter)

## Usage

Install via [composer](https://getcomposer.org):

```shell
composer require neos/eventstore
```

### Appending events

#### Commit a single event to the event store

```php
$eventStore->commit(
    streamName: StreamName::fromString('some-stream'),
    events: Events::single(EventId::create(), EventType::fromString('SomeEventType'), EventData::fromString('{"foo": "bar"}'), EventMetadata::none()),
    expectedVersion: ExpectedVersion::ANY(),
);
```

#### Commit multiple events at once

```php
$correlationId = Uuid::uuid4()->toString();
$eventStore->commit(
    streamName: StreamName::fromString('some-stream'),
    events: Events::fromArray([
        new Event(EventId::create(), EventType::fromString('SomeEventType'), EventData::fromString('foo'), EventMetadata::fromArray(['correlationId' => $correlationId])),
        new Event(EventId::create(), EventType::fromString('SomeOtherType'), EventData::fromString('bar'), EventMetadata::fromArray(['correlationId' => $correlationId])),
    ]),
    expectedVersion: ExpectedVersion::ANY(),
);
```

> **Note**
> Multiple events can only ever be appended to the same stream at once

#### Expected version

Event-sourced systems are eventual consistent. This basically means that the models, that are used to base decisions on, might be out of date.
The `ExpectedVersion` can be used to make sure, that no new events where appended to the stream since the model was reconstituted from the events.
That mechanism can be used to implement event-sourced aggregates.

##### ExpectedVersion::ANY

`ExpectedVersion::ANY()` (as used in the examples above) skips the version check entirely and should only be used if no hard constraints are required.

##### ExpectedVersion::NO_STREAM

`ExpectedVersion::NO_STREAM()` can be used to make sure that a given stream does not yet exist.
This is useful for events that represent the creation of an entity:

```php
$eventStore->commit(
    streamName: StreamName::fromString('customer-' . $customerId->value),
    events: Events::single(EventId::create(), EventType::fromString('CustomerHasSignedUp'), EventData::fromString($customerData->toJson()), EventMetadata::none()),
    expectedVersion: ExpectedVersion::NO_STREAM(),
);
```

If the same code was executed again (with the same `$customId`) it would fail

##### ExpectedVersion::STREAM_EXISTS

`ExpectedVersion::STREAM_EXISTS()` is basically the opposite of `NO_STREAM`: It fails if no events have been committed to the stream before

### Reading events

#### Iterate over all events of a stream

To load all events and output their sequence number and type:

```php
foreach ($eventStore->load(StreamName::fromString('some-stream')) as $eventEnvelope) {
    echo $eventEnvelope->sequenceNumber->value . ': ' . $eventEnvelope->event->type->value . PHP_EOL;
}
```

#### Filter event stream

`EventStoreInterface::load()` expects a second, optional, argument that allows to filter the event stream.
This can be used to only load events of a certain type:

```php
$stream = $eventStore->load(
    streamName: StreamName::fromString('some-stream'),
    filter: EventStreamFilter::create(eventTypes: EventTypes::create(EventType::fromString('SomeEventType')))
);
```

> [!NOTE]
> Filtering events based on their type is a low-level optimization that is usually not required and should only be applied if needed

#### Navigate the event stream

The resulting `EventStreamInterface` of the `EventStoreInterface::load()` call is a lazy representation of the stream.
Depending on the actual implementation the events are only loaded whenever they are *accessed*.

The `EventStreamInterface` provides four methods to affect ordering and window of the events to load:

* `withMinimumSequenceNumber()` to specify the lowest `SequenceNumber` that should be included in the stream
* `withMaximumSequenceNumber()` to specify the highest `SequenceNumber` that should be included in the stream
* `limit()` to specify the maximum number of events to load in total
* `backwards()` to load events in *descending* order.

Usually the `withMinimumSequenceNumber()` is used to only load events that have not been processed yet by an event handler,
but sometimes it can be useful to allow for arbitrary event stream navigation.

The following example will read at most 10 events with sequence number between 500 and 1000 in descending order:

```php
$stream = $eventStore->load(StreamName::fromString('some-stream'))
    ->withMinimumSequenceNumber(SequenceNumber::fromInteger(500))
    ->withMaximumSequenceNumber(SequenceNumber::fromInteger(1000))
    ->limit(10)
    ->backwards();
```

### Deleting events

Events form the unique source of truth in a system and thus should never be deleted.
In theory.
In practice, it can be useful to be able to remove streams that are not in use any longer.

The following example deletes all events from "some-stream":

```php
$eventStore->deleteStream(StreamName::fromString('some-stream'));
```

> [!WARNING]
> For obvious reasons, this method should only be used with great care.
> Mostly there are better ways to solve issues that seem to require deletion of events.
> Also note, that some Event store implementations might not support this feature

### More examples

@see [tests](tests) for more examples.

## Contribution

Contributions in the form of [issues](https://github.com/neos/eventstore/issues), [pull requests](https://github.com/neos/eventstore/pulls) or [discussions](https://github.com/neos/eventstore/discussions) are highly appreciated

## License

See [LICENSE](./LICENSE)

<?php

declare(strict_types=1);

namespace Prooph\PdoEventStore;

use PDO;
use Prooph\EventStore\Common\SystemEventTypes;
use Prooph\EventStore\Common\SystemStreams;
use Prooph\EventStore\DetailedSubscriptionInformation;
use Prooph\EventStore\EventData;
use Prooph\EventStore\EventId;
use Prooph\EventStore\EventReadResult;
use Prooph\EventStore\EventReadStatus;
use Prooph\EventStore\EventStorePersistentSubscription;
use Prooph\EventStore\EventStoreSubscriptionConnection;
use Prooph\EventStore\EventStoreTransaction;
use Prooph\EventStore\EventStoreTransactionConnection;
use Prooph\EventStore\Exception\ConnectionException;
use Prooph\EventStore\Exception\RuntimeException;
use Prooph\EventStore\Exception\WrongExpectedVersion;
use Prooph\EventStore\ExpectedVersion;
use Prooph\EventStore\Internal\Consts;
use Prooph\EventStore\Internal\PersistentSubscriptionCreateResult;
use Prooph\EventStore\Internal\PersistentSubscriptionDeleteResult;
use Prooph\EventStore\Internal\PersistentSubscriptionUpdateResult;
use Prooph\EventStore\Internal\ReplayParkedResult;
use Prooph\EventStore\PersistentSubscriptionSettings;
use Prooph\EventStore\SliceReadStatus;
use Prooph\EventStore\StreamEventsSlice;
use Prooph\EventStore\StreamMetadata;
use Prooph\EventStore\StreamMetadataResult;
use Prooph\EventStore\SystemSettings;
use Prooph\EventStore\UserCredentials;
use Prooph\EventStore\WriteResult;
use Prooph\PdoEventStore\ClientOperations\AcquireStreamLockOperation;
use Prooph\PdoEventStore\ClientOperations\AppendToStreamOperation;
use Prooph\PdoEventStore\ClientOperations\CreatePersistentSubscriptionOperation;
use Prooph\PdoEventStore\ClientOperations\DeletePersistentSubscriptionOperation;
use Prooph\PdoEventStore\ClientOperations\DeleteStreamOperation;
use Prooph\PdoEventStore\ClientOperations\ReadEventOperation;
use Prooph\PdoEventStore\ClientOperations\ReadStreamEventsBackwardOperation;
use Prooph\PdoEventStore\ClientOperations\ReadStreamEventsForwardOperation;
use Prooph\PdoEventStore\ClientOperations\ReleaseStreamLockOperation;
use Prooph\PdoEventStore\ClientOperations\UpdatePersistentSubscriptionOperation;

final class PdoEventStoreConnection implements EventStoreSubscriptionConnection, EventStoreTransactionConnection
{
    /** @var PDO */
    private $connection;

    /** @var array */
    private $locks = [];

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
        $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function connect(): void
    {
        // do nothing
    }

    public function close(): void
    {
        // do nothing
    }

    public function deleteStream(
        string $stream,
        bool $hardDelete,
        UserCredentials $userCredentials = null
    ): void {
        if (empty($stream)) {
            throw new \InvalidArgumentException('Stream cannot be empty');
        }

        (new DeleteStreamOperation())($this->connection, $stream, $hardDelete);
    }

    /**
     * @param string $stream
     * @param int $expectedVersion
     * @param null|UserCredentials $userCredentials
     * @param EventData[] $events
     * @return WriteResult
     */
    public function appendToStream(
        string $stream,
        int $expectedVersion,
        array $events,
        UserCredentials $userCredentials = null
    ): WriteResult {
        if (empty($stream)) {
            throw new \InvalidArgumentException('Stream cannot be empty');
        }

        if (empty($events)) {
            throw new \InvalidArgumentException('Empty stream given');
        }

        if (isset($this->locks[$stream])) {
            throw new RuntimeException('Lock on stream ' . $stream . ' is already acquired');
        }

        return (new AppendToStreamOperation())(
            $stream,
            $expectedVersion,
            $events,
            $userCredentials,
            true
        );
    }

    public function readEvent(
        string $stream,
        int $eventNumber,
        UserCredentials $userCredentials = null
    ): EventReadResult {
        if (empty($stream)) {
            throw new \InvalidArgumentException('Stream cannot be empty');
        }

        if ($eventNumber < -1) {
            throw new \InvalidArgumentException('EventNumber cannot be smaller then -1');
        }

        return (new ReadEventOperation())($this->connection, $stream, $eventNumber);
    }

    public function readStreamEventsForward(
        string $stream,
        int $start,
        int $count,
        bool $resolveLinkTos = true,
        UserCredentials $userCredentials = null
    ): StreamEventsSlice {
        if (empty($stream)) {
            throw new \InvalidArgumentException('Stream cannot be empty');
        }

        if ($start < 0) {
            throw new \InvalidArgumentException('Start cannot be negative');
        }

        if ($count < 0) {
            throw new \InvalidArgumentException('Count cannot be negative');
        }

        if ($count > Consts::MaxReadSize) {
            throw new \InvalidArgumentException(
                'Count should be less than ' . Consts::MaxReadSize . '. For larger reads you should page.'
            );
        }

        return (new ReadStreamEventsForwardOperation())($this->connection, $stream, $start, $count);
    }

    public function readStreamEventsBackward(
        string $stream,
        int $start,
        int $count,
        bool $resolveLinkTos = true,
        UserCredentials $userCredentials = null
    ): StreamEventsSlice {
        if (empty($stream)) {
            throw new \InvalidArgumentException('Stream cannot be empty');
        }

        if ($start < 0) {
            throw new \InvalidArgumentException('Start cannot be negative');
        }

        if ($count < 0) {
            throw new \InvalidArgumentException('Count cannot be negative');
        }

        if ($count > Consts::MaxReadSize) {
            throw new \InvalidArgumentException(
                'Count should be less than ' . Consts::MaxReadSize . '. For larger reads you should page.'
            );
        }

        return (new ReadStreamEventsBackwardOperation())($this->connection, $stream, $start, $count);
    }

    public function setStreamMetadata(
        string $stream,
        int $expectedMetaStreamVersion,
        StreamMetadata $metadata,
        UserCredentials $userCredentials = null
    ): WriteResult {
        if (empty($stream)) {
            throw new \InvalidArgumentException('Stream cannot be empty');
        }

        if (SystemStreams::isMetastream($stream)) {
            throw new \InvalidArgumentException(sprintf(
                'Setting metadata for metastream \'%s\' is not supported.',
                $stream
            ));
        }

        $metaEvent = new EventData(
            EventId::generate(),
            SystemEventTypes::StreamMetadata,
            true,
            json_encode($metadata->toArray()),
            ''
        );

        return (new AppendToStreamOperation())(
            $this->connection,
            SystemStreams::metastreamOf($stream),
            $expectedMetaStreamVersion,
            [$metaEvent],
            $userCredentials,
            true
        );
    }

    public function getStreamMetadata(string $stream, UserCredentials $userCredentials = null): StreamMetadataResult
    {
        if (empty($stream)) {
            throw new \InvalidArgumentException('Stream cannot be empty');
        }

        $eventReadResult = $this->readEvent(
            SystemStreams::metastreamOf($stream),
            -1,
            $userCredentials
        );

        switch ($eventReadResult->status()->value()) {
            case EventReadStatus::Success:
                $event = $eventReadResult->event();

                if (null === $event) {
                    throw new \UnexpectedValueException('Event is null while operation result is Success');
                }

                return new StreamMetadataResult(
                    $stream,
                    false,
                    $event->eventNumber(),
                    $event->data()
                );
            case EventReadStatus::NotFound:
            case EventReadStatus::NoStream:
                return new StreamMetadataResult($stream, false, -1, '');
            case EventReadStatus::StreamDeleted:
                return new StreamMetadataResult($stream, true, PHP_INT_MAX, '');
            default:
                throw new \OutOfRangeException('Unexpected ReadEventResult: ' . $eventReadResult->status()->value());
        }
    }

    public function setSystemSettings(SystemSettings $settings, UserCredentials $userCredentials = null): WriteResult
    {
        return $this->appendToStream(
            SystemStreams::SettingsStream,
            ExpectedVersion::Any,
            [
                new EventData(
                    EventId::generate(),
                    SystemEventTypes::Settings,
                    true,
                    json_encode($settings->toArray()),
                    ''
                ),
            ],
            $userCredentials
        );
    }

    public function createPersistentSubscription(
        string $stream,
        string $groupName,
        PersistentSubscriptionSettings $settings,
        UserCredentials $userCredentials = null
    ): PersistentSubscriptionCreateResult {
        if (empty($stream)) {
            throw new \InvalidArgumentException('Stream cannot be empty');
        }

        if (empty($groupName)) {
            throw new \InvalidArgumentException('Group name cannot be empty');
        }

        return (new CreatePersistentSubscriptionOperation())(
            $this->connection,
            $stream,
            $groupName,
            $settings,
            $userCredentials
        );
    }

    public function updatePersistentSubscription(
        string $stream,
        string $groupName,
        PersistentSubscriptionSettings $settings,
        UserCredentials $userCredentials = null
    ): PersistentSubscriptionUpdateResult {
        if (empty($stream)) {
            throw new \InvalidArgumentException('Stream cannot be empty');
        }

        if (empty($groupName)) {
            throw new \InvalidArgumentException('Group name cannot be empty');
        }

        return (new UpdatePersistentSubscriptionOperation())(
            $this->connection,
            $stream,
            $groupName,
            $settings,
            $userCredentials
        );
    }

    public function deletePersistentSubscription(
        string $stream,
        string $groupName,
        UserCredentials $userCredentials = null
    ): PersistentSubscriptionDeleteResult {
        if (empty($stream)) {
            throw new \InvalidArgumentException('Stream cannot be empty');
        }

        if (empty($groupName)) {
            throw new \InvalidArgumentException('Group name cannot be empty');
        }

        return (new DeletePersistentSubscriptionOperation())(
            $this->connection,
            $stream,
            $groupName,
            $userCredentials
        );
    }

    public function connectToPersistentSubscription(
        string $stream,
        string $groupName,
        callable $eventAppeared,
        callable $subscriptionDropped = null,
        int $bufferSize = 10,
        bool $autoAck = true,
        UserCredentials $userCredentials = null
    ): EventStorePersistentSubscription {
        if (empty($stream)) {
            throw new \InvalidArgumentException('Stream cannot be empty');
        }

        if (empty($groupName)) {
            throw new \InvalidArgumentException('Group name cannot be empty');
        }
    }

    public function replayParked(
        string $stream,
        string $groupName,
        UserCredentials $userCredentials = null
    ): ReplayParkedResult {
        if (empty($stream)) {
            throw new \InvalidArgumentException('Stream cannot be empty');
        }

        if (empty($groupName)) {
            throw new \InvalidArgumentException('Group name cannot be empty');
        }

        // TODO: Implement replayParked() method.
    }

    public function getInformationForAllSubscriptions(
        UserCredentials $userCredentials = null
    ): array {
        // TODO: Implement getInformationForAllSubscriptions() method.
    }

    public function getInformationForSubscriptionsWithStream(
        string $stream,
        UserCredentials $userCredentials = null
    ): array {
        if (empty($stream)) {
            throw new \InvalidArgumentException('Stream cannot be empty');
        }

        // TODO: Implement getInformationForSubscriptionsWithStream() method.
    }

    public function getInformationForSubscription(
        string $stream,
        string $groupName,
        UserCredentials $userCredentials = null
    ): DetailedSubscriptionInformation {
        if (empty($stream)) {
            throw new \InvalidArgumentException('Stream cannot be empty');
        }

        if (empty($groupName)) {
            throw new \InvalidArgumentException('Group name cannot be empty');
        }

        // TODO: Implement getInformationForSubscription() method.
    }

    public function startTransaction(
        string $stream,
        int $expectedVersion,
        UserCredentials $userCredentials = null
    ): EventStoreTransaction {
        if (empty($stream)) {
            throw new \InvalidArgumentException('Stream cannot be empty');
        }

        if (isset($this->locks[$stream])) {
            throw new ConnectionException('Lock on stream ' . $stream . ' is already acquired');
        }

        if ($this->connection->inTransaction()) {
            throw new ConnectionException('PDO connection is already in transaction');
        }

        (new AcquireStreamLockOperation())($this->connection, $stream);

        /* @var StreamEventsSlice $slice */
        $slice = (new ReadStreamEventsBackwardOperation())(
            $this->connection,
            $stream,
            PHP_INT_MAX,
            1
        );

        switch ($expectedVersion) {
            case ExpectedVersion::NoStream:
                if (! $slice->status()->equals(SliceReadStatus::streamNotFound())) {
                    (new ReleaseStreamLockOperation())($this->connection, $stream);
                    unset($this->locks[$stream]);

                    if (! $slice->isEndOfStream()) {
                        throw WrongExpectedVersion::withCurrentVersion($stream, $expectedVersion, $slice->lastEventNumber());
                    }
                    throw WrongExpectedVersion::withExpectedVersion($stream, $expectedVersion);
                }
                break;
            case ExpectedVersion::StreamExists:
                if (! $slice->status()->equals(SliceReadStatus::success())) {
                    (new ReleaseStreamLockOperation())($this->connection, $stream);
                    unset($this->locks[$stream]);

                    if (! $slice->isEndOfStream()) {
                        throw WrongExpectedVersion::withCurrentVersion($stream, $expectedVersion, $slice->lastEventNumber());
                    }
                    throw WrongExpectedVersion::withExpectedVersion($stream, $expectedVersion);
                }
                break;
            case ExpectedVersion::EmptyStream:
                if (! $slice->status()->equals(SliceReadStatus::success())
                    && ! $slice->isEndOfStream()
                ) {
                    (new ReleaseStreamLockOperation())($this->connection, $stream);
                    unset($this->locks[$stream]);

                    throw WrongExpectedVersion::withCurrentVersion($stream, $expectedVersion, $slice->lastEventNumber());
                }
                break;
            case ExpectedVersion::Any:
                break;
            default:
                if (! $slice->status()->equals(SliceReadStatus::success())
                    && $expectedVersion !== $slice->lastEventNumber()
                ) {
                    (new ReleaseStreamLockOperation())($this->connection, $stream);
                    unset($this->locks[$stream]);

                    throw WrongExpectedVersion::withCurrentVersion($stream, $expectedVersion, $slice->lastEventNumber());
                }
                break;
        }

        $this->locks[$stream] = [
            'id' => random_int(0, PHP_INT_MAX),
            'expectedVersion' => $slice->lastEventNumber(),
        ];

        $this->connection->beginTransaction();

        return new EventStoreTransaction(
            $this->locks[$stream]['id'],
            $userCredentials,
            $this
        );
    }

    public function transactionalWrite(
        EventStoreTransaction $transaction,
        array $events,
        UserCredentials $userCredentials = null
    ): void {
        if (empty($events)) {
            throw new \InvalidArgumentException('Empty stream given');
        }

        $found = false;

        foreach ($this->locks as $stream => $data) {
            if ($data['id'] === $transaction->transactionId()) {
                $expectedVersion = $data['expectedVersion'];
                $found = true;
                break;
            }
        }

        if (false === $found) {
            throw new ConnectionException(
                'No lock for transaction with id ' . $transaction->transactionId() . ' found'
            );
        }

        if (! $this->connection->inTransaction()) {
            throw new ConnectionException('PDO connection is not in transaction');
        }

        (new AppendToStreamOperation())(
            $this->connection,
            $stream,
            $expectedVersion,
            $events,
            $userCredentials,
            false
        );

        $this->locks[$stream]['expectedVersion'] += count($events);
    }

    public function commitTransaction(
        EventStoreTransaction $transaction,
        UserCredentials $userCredentials = null
    ): WriteResult {
        $found = false;

        foreach ($this->locks as $stream => $data) {
            if ($data['id'] === $transaction->transactionId()) {
                $found = true;
                break;
            }
        }

        if (false === $found) {
            throw new ConnectionException(
                'No lock for transaction with id ' . $transaction->transactionId() . ' found'
            );
        }

        if (! $this->connection->inTransaction()) {
            throw new ConnectionException('PDO connection is not in transaction');
        }

        $this->connection->commit();

        (new ReleaseStreamLockOperation())($this->connection, $stream);

        unset($this->locks[$stream]);

        return new WriteResult();
    }
}

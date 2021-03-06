<?php

declare(strict_types=1);

namespace Prooph\HttpEventStore;

use Http\Client\HttpClient;
use Http\Message\RequestFactory;
use Http\Message\UriFactory;
use Prooph\EventStore\Common\SystemEventTypes;
use Prooph\EventStore\Common\SystemStreams;
use Prooph\EventStore\DetailedSubscriptionInformation;
use Prooph\EventStore\EventData;
use Prooph\EventStore\EventId;
use Prooph\EventStore\EventReadResult;
use Prooph\EventStore\EventReadStatus;
use Prooph\EventStore\EventStorePersistentSubscription;
use Prooph\EventStore\EventStoreSubscriptionConnection;
use Prooph\EventStore\ExpectedVersion;
use Prooph\EventStore\Internal\Consts;
use Prooph\EventStore\Internal\PersistentSubscriptionCreateResult;
use Prooph\EventStore\Internal\PersistentSubscriptionDeleteResult;
use Prooph\EventStore\Internal\PersistentSubscriptionUpdateResult;
use Prooph\EventStore\Internal\ReplayParkedResult;
use Prooph\EventStore\PersistentSubscriptionSettings;
use Prooph\EventStore\StreamEventsSlice;
use Prooph\EventStore\StreamMetadata;
use Prooph\EventStore\StreamMetadataResult;
use Prooph\EventStore\SubscriptionInformation;
use Prooph\EventStore\SystemSettings;
use Prooph\EventStore\UserCredentials;
use Prooph\EventStore\WriteResult;
use Prooph\HttpEventStore\ClientOperations\AppendToStreamOperation;
use Prooph\HttpEventStore\ClientOperations\CreatePersistentSubscriptionOperation;
use Prooph\HttpEventStore\ClientOperations\DeletePersistentSubscriptionOperation;
use Prooph\HttpEventStore\ClientOperations\DeleteStreamOperation;
use Prooph\HttpEventStore\ClientOperations\GetInformationForAllSubscriptionsOperation;
use Prooph\HttpEventStore\ClientOperations\GetInformationForSubscriptionOperation;
use Prooph\HttpEventStore\ClientOperations\GetInformationForSubscriptionsWithStreamOperation;
use Prooph\HttpEventStore\ClientOperations\PersistentSubscriptionOperations;
use Prooph\HttpEventStore\ClientOperations\ReadEventOperation;
use Prooph\HttpEventStore\ClientOperations\ReadStreamEventsBackwardOperation;
use Prooph\HttpEventStore\ClientOperations\ReadStreamEventsForwardOperation;
use Prooph\HttpEventStore\ClientOperations\ReplayParkedOperation;
use Prooph\HttpEventStore\ClientOperations\UpdatePersistentSubscriptionOperation;

class HttpEventStoreConnection implements EventStoreSubscriptionConnection
{
    /** @var HttpClient */
    private $httpClient;
    /** @var RequestFactory */
    private $requestFactory;
    /** @var UriFactory */
    private $uriFactory;
    /** @var ConnectionSettings */
    private $settings;
    /** @var string */
    private $baseUri;

    public function __construct(
        HttpClient $httpClient,
        RequestFactory $requestFactory,
        UriFactory $uriFactory,
        ConnectionSettings $settings = null
    ) {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->uriFactory = $uriFactory;
        $this->settings = $settings ?? ConnectionSettings::default();
        $this->baseUri = sprintf(
            '%s://%s:%s',
            $this->settings->useSslConnection() ? 'https' : 'http',
            $this->settings->endPoint()->host(),
            $this->settings->endPoint()->port()
        );
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

        (new DeleteStreamOperation())(
            $this->httpClient,
            $this->requestFactory,
            $this->uriFactory,
            $this->baseUri,
            $stream,
            $hardDelete,
            $userCredentials ?? $this->settings->defaultUserCredentials()
        );
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

        return (new AppendToStreamOperation())(
            $this->httpClient,
            $this->requestFactory,
            $this->uriFactory,
            $this->baseUri,
            $stream,
            $expectedVersion,
            $events,
            $userCredentials ?? $this->settings->defaultUserCredentials()
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

        return (new ReadEventOperation())(
            $this->httpClient,
            $this->requestFactory,
            $this->uriFactory,
            $this->baseUri,
            $stream,
            $eventNumber,
            $userCredentials ?? $this->settings->defaultUserCredentials()
        );
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

        return (new ReadStreamEventsForwardOperation())(
            $this->httpClient,
            $this->requestFactory,
            $this->uriFactory,
            $this->baseUri,
            $stream,
            $start,
            $count,
            $resolveLinkTos,
            $userCredentials ?? $this->settings->defaultUserCredentials()
        );
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

        return (new ReadStreamEventsBackwardOperation())(
            $this->httpClient,
            $this->requestFactory,
            $this->uriFactory,
            $this->baseUri,
            $stream,
            $start,
            $count,
            $resolveLinkTos,
            $userCredentials ?? $this->settings->defaultUserCredentials()
        );
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
            $this->httpClient,
            $this->requestFactory,
            $this->uriFactory,
            $this->baseUri,
            SystemStreams::metastreamOf($stream),
            $expectedMetaStreamVersion,
            [$metaEvent],
            $userCredentials ?? $this->settings->defaultUserCredentials()
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
            $userCredentials ?? $this->settings->defaultUserCredentials()
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
            $userCredentials ?? $this->settings->defaultUserCredentials()
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
            $this->httpClient,
            $this->requestFactory,
            $this->uriFactory,
            $this->baseUri,
            $stream,
            $groupName,
            $settings,
            $userCredentials ?? $this->settings->defaultUserCredentials()
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
            $this->httpClient,
            $this->requestFactory,
            $this->uriFactory,
            $this->baseUri,
            $stream,
            $groupName,
            $settings,
            $userCredentials ?? $this->settings->defaultUserCredentials()
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
            $this->httpClient,
            $this->requestFactory,
            $this->uriFactory,
            $this->baseUri,
            $stream,
            $groupName,
            $userCredentials ?? $this->settings->defaultUserCredentials()
        );
    }

    /**
     * @param string $stream
     * @param string $groupName
     * @param callable(EventStorePersistentSubscription $subscription, RecordedEvent $event, int $retryCount, Task $task) $eventAppeared
     * @param callable(EventStorePersistentSubscription $subscription, SubscriptionDropReason $reason, Throwable $error)|null $subscriptionDropped
     * @param int $bufferSize
     * @param bool $autoAck
     * @param bool $autoNack
     * @param UserCredentials|null $userCredentials
     * @return EventStorePersistentSubscription
     */
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

        return new EventStorePersistentSubscription(
            new PersistentSubscriptionOperations(
                $this->httpClient,
                $this->requestFactory,
                $this->uriFactory,
                $this->baseUri,
                $stream,
                $groupName,
                $userCredentials ?? $this->settings->defaultUserCredentials()
            ),
            $groupName,
            $stream,
            $eventAppeared,
            $subscriptionDropped,
            $bufferSize,
            $autoAck
        );
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

        return (new ReplayParkedOperation())(
            $this->httpClient,
            $this->requestFactory,
            $this->uriFactory,
            $this->baseUri,
            $stream,
            $groupName,
            $userCredentials ?? $this->settings->defaultUserCredentials()
        );
    }

    /**
     * @return SubscriptionInformation[]
     */
    public function getInformationForAllSubscriptions(
        UserCredentials $userCredentials = null
    ): array {
        return (new GetInformationForAllSubscriptionsOperation())(
            $this->httpClient,
            $this->requestFactory,
            $this->uriFactory,
            $this->baseUri,
            $userCredentials ?? $this->settings->defaultUserCredentials()
        );
    }

    /**
     * @return SubscriptionInformation[]
     */
    public function getInformationForSubscriptionsWithStream(
        string $stream,
        UserCredentials $userCredentials = null
    ): array {
        if (empty($stream)) {
            throw new \InvalidArgumentException('Stream cannot be empty');
        }

        return (new GetInformationForSubscriptionsWithStreamOperation())(
            $this->httpClient,
            $this->requestFactory,
            $this->uriFactory,
            $this->baseUri,
            $stream,
            $userCredentials ?? $this->settings->defaultUserCredentials()
        );
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

        return (new GetInformationForSubscriptionOperation())(
            $this->httpClient,
            $this->requestFactory,
            $this->uriFactory,
            $this->baseUri,
            $stream,
            $groupName,
            $userCredentials ?? $this->settings->defaultUserCredentials()
        );
    }
}

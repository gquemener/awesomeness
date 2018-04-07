<?php

declare(strict_types=1);
require 'vendor/autoload.php';

$connection = new \Prooph\EventStoreHttpClient\EventStoreHttpConnection(
    new \Http\Client\Curl\Client(new \Http\Message\MessageFactory\DiactorosMessageFactory()),
    new \Http\Message\MessageFactory\DiactorosMessageFactory(),
    new \Http\Message\UriFactory\DiactorosUriFactory(),
    new \Prooph\EventStoreHttpClient\ConnectionSettings(new \Prooph\EventStore\IpEndPoint('eventstore', 2113), false)
);

$task = $connection->createPersistentSubscription(
    'sasastream',
    'test',
    \Prooph\EventStore\PersistentSubscriptionSettings::default(),
    new \Prooph\EventStore\UserCredentials('admin', 'changeit')
);

var_dump($task->result());

$task = $connection->updatePersistentSubscription(
    'sasastream',
    'test',
    new \Prooph\EventStore\PersistentSubscriptionSettings(
        true,
        0,
        false,
        2000,
        500,
        10,
        20,
        1000,
        500,
        0,
        30000,
        10,
        \Prooph\EventStore\NamedConsumerStrategy::roundRobin()
    ),
    new \Prooph\EventStore\UserCredentials('admin', 'changeit')
);

var_dump($task->result());
/*
$task = $connection->deletePersistentSubscription(
    'sasastream',
    'test',
    new \Prooph\EventStore\UserCredentials('admin', 'changeit')
);

var_dump($task->result());
*/
$task = $connection->getInformationForAllSubscriptionsAsync();

var_dump($task->result());

$task = $connection->getInformationForSubscriptionsWithStreamAsync('sasastream');

var_dump($task->result());

$task = $connection->getInformationForSubscriptionAsync('sasastream', 'test');

var_dump($task->result());
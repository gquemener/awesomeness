# Awesomeness

Experimental new EventStore Implementation.

Might be merged into prooph one day.

Mono-Repository combining multiple prooph components into one (for easier development accross repos).

## EventStore

Base classes for usage with all event store implementations.

Note: some implementation details are skipped for now, some of those are:
- Subscriptions

## Greg's EventStore

This is the first implementation being delivered

## Postgres EventStore

This will get targeted second, let's see if we can implement everything or only parts of it.

## MySQL / MariaDB EventStore

Later...

## InMemory EventStore

Not now...

## Test-Script

1) Download Greg's EventStore (only working implementation so far):

`wget https://eventstore.org/downloads/EventStore-OSS-Ubuntu-14.04-v4.1.0.tar.gz`

2) Extract

`tar -xf EventStore-OSS-Ubuntu-14.04-v4.1.0.tar.gz`

3) Change Dir

`cd EventStore-OSS-Ubuntu-14.04-v4.1.0`

4) Start Server

`./run-node.sh --db ./ESData --run-projections=all`

5) Start Test-Script

`php test-script.php`

6) Check output

7) Run again

`php test-script.php`

Now we have: `PHP Fatal error:  Uncaught Prooph\EventStore\Exception\WrongExpectedVersion: Append failed due to WrongExpectedVersion. Stream: sasastream, Expected version: -1, Current version: 1`

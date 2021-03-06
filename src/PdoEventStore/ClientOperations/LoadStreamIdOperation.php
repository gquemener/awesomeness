<?php

declare(strict_types=1);

namespace Prooph\PdoEventStore\ClientOperations;

use PDO;
use Prooph\EventStore\Common\SystemStreams;
use Prooph\EventStore\Exception\AccessDenied;
use Prooph\EventStore\Exception\RuntimeException;
use Prooph\EventStore\Exception\StreamDeleted;
use Prooph\EventStore\SystemSettings;
use Prooph\PdoEventStore\Internal\LoadStreamIdResult;
use Prooph\PdoEventStore\Internal\StreamOperation;

/** @internal */
class LoadStreamIdOperation
{
    public function __invoke(
        PDO $connection,
        string $stream,
        int $operation,
        SystemSettings $systemSettings,
        array $userRoles
    ): LoadStreamIdResult {
        switch ($connection->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            case 'mysql':
                $concat = "GROUP_CONCAT(stream_acl.role SEPARATOR ',')";
                break;
            case 'pgsql':
                $concat = "STRING_AGG(stream_acl.role, ',')";
                break;
            default:
                throw new RuntimeException('Invalid PDO driver used');
        }

        $statement = $connection->prepare(<<<SQL
SELECT streams.stream_id, streams.mark_deleted, streams.deleted, $concat as stream_roles
    FROM streams
    LEFT JOIN stream_acl ON streams.stream_id = stream_acl.stream_id AND stream_acl.operation = ?
    WHERE streams.stream_name = ?
    GROUP BY streams.stream_id, streams.mark_deleted, streams.deleted
    LIMIT 1;
SQL
        );
        $statement->execute([$operation, $stream]);
        $statement->setFetchMode(PDO::FETCH_OBJ);
        $data = $statement->fetch();

        if (null === $data) {
            if (! SystemStreams::isSystemStream($stream)) {
                switch ($operation) {
                    case StreamOperation::Read:
                        $toCheck = $systemSettings->userStreamAcl()->readRoles();
                        break;
                    case StreamOperation::Write:
                        $toCheck = $systemSettings->userStreamAcl()->writeRoles();
                        break;
                    case StreamOperation::Delete:
                        $toCheck = $systemSettings->userStreamAcl()->deleteRoles();
                        break;
                }
            } else {
                $isMetaStream = SystemStreams::isMetastream($stream);

                switch ($operation) {
                    case StreamOperation::Read:
                        if ($isMetaStream) {
                            $toCheck = $systemSettings->systemStreamAcl()->metaReadRoles();
                        } else {
                            $toCheck = $systemSettings->systemStreamAcl()->readRoles();
                        }
                        break;
                    case StreamOperation::Write:
                        if ($isMetaStream) {
                            $toCheck = $systemSettings->systemStreamAcl()->metaWriteRoles();
                        } else {
                            $toCheck = $systemSettings->systemStreamAcl()->writeRoles();
                        }
                        break;
                    case StreamOperation::Delete:
                        $toCheck = $systemSettings->systemStreamAcl()->deleteRoles();
                        break;
                }
            }
        } else {
            if ($data->mark_deleted || $data->deleted) {
                throw StreamDeleted::with($stream);
            }

            $toCheck = explode(',', $data->stream_roles);
        }

        if (empty(array_intersect($userRoles, $toCheck))) {
            throw AccessDenied::toStream($stream);
        }

        if (null === $data) {
            return new LoadStreamIdResult(false, null);
        }

        return new LoadStreamIdResult(true, $data->stream_id);
    }
}

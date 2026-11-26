<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\RemoteApiException;
use Api\V3\Exception\SyncException;
use Api\V3\Exception\SyncRecordException;
use Api\V3\HttpException;

/**
 * A failure as a sync job reports it. A remote refusal is named by its status
 * and message, and this server's own refusals by theirs. An error inside this
 * server is never shown by its message: a DatabaseException's is "Internal
 * server error", which told the job's reader nothing, and the rest can carry
 * a statement or a path. It is logged whole, and the job says where.
 */
final class SyncFailure
{
    /**
     * What to record for $error, logging it first when it is this server's
     * own; $where names the job or record it stopped, in the log and in the
     * sentence, so the one finds the other.
     */
    public static function report(\Throwable $error, string $where): string
    {
        if (self::isInternal($error)) {
            $tag = self::tag($where);
            // Both helpers or neither: a worker that lacks one must still record its job's end.
            error_log(function_exists('p202UncaughtLogLine') && function_exists('p202FailedQueryMessage')
                ? p202UncaughtLogLine($tag, $error)
                : self::logLine($tag, $error));
        }

        return self::describe($error, $where);
    }

    /** The sentence report() records, without logging: for a failure report() has already logged. */
    public static function describe(\Throwable $error, string $where): string
    {
        if ($error instanceof RemoteApiException) {
            return $error->describe();
        }
        if (!self::isInternal($error)) {
            return $error->getMessage();
        }

        return 'an error inside this server (' . self::shortClass($error) . '), not an answer from either '
            . 'instance; the server\'s error log has it under "' . self::tag($where) . '"';
    }

    /**
     * The chain, for a caller that has not loaded functions-shared.php; a
     * query's own message can quote a value, so only its errno is kept.
     */
    private static function logLine(string $tag, \Throwable $error): string
    {
        $parts = [];
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            $message = $e instanceof \mysqli_sql_exception ? 'errno ' . $e->getCode() : $e->getMessage();
            $parts[] = $e::class . ': ' . $message . ' in ' . $e->getFile() . ':' . $e->getLine();
        }

        return $tag . ': ' . implode("\nPrevious: ", $parts);
    }

    private static function tag(string $where): string
    {
        return 'Prosper202 sync: ' . $where;
    }

    /** An error this server raised about itself, not a refusal written for a client. */
    public static function isInternal(\Throwable $error): bool
    {
        if (
            $error instanceof RemoteApiException
            || $error instanceof SyncException
            || $error instanceof SyncRecordException
        ) {
            return false;
        }

        return $error instanceof DatabaseException || !$error instanceof HttpException;
    }

    /** Whether $error is, or was caused by, an error inside this server. */
    public static function hasInternalCause(\Throwable $error): bool
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof SyncException || $e instanceof RemoteApiException) {
                return false;
            }
            if (self::isInternal($e)) {
                return true;
            }
        }

        return false;
    }

    private static function shortClass(\Throwable $error): string
    {
        $class = $error::class;
        $at = strrpos($class, '\\');

        return $at === false ? $class : substr($class, $at + 1);
    }
}

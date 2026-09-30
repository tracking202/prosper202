<?php

declare(strict_types=1);

namespace Prosper202\Identity;

/**
 * A signal this link was about to create was created by a concurrent link
 * first. Nothing is wrong with either click: running the link again reads
 * the other transaction's row and merges into its visitor, which is the
 * answer the two clicks should have had. ClickIdentity::attach() retries on
 * it as it does on a deadlock.
 *
 * Under REPEATABLE READ the two `SELECT ... FOR UPDATE` probes of a signal
 * that does not exist yet take gap locks, and the two inserts deadlock
 * (retried). Under READ COMMITTED no gap lock is taken, the second insert
 * waits for the first to commit and then fails on the duplicate key — this
 * exception — and without the retry that click lost its link.
 */
final class IdentityRaceException extends \RuntimeException
{
}

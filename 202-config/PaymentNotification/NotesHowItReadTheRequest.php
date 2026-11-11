<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

/**
 * A provider whose URL can be read more than one way (JVZoo's 1.9.56 URL)
 * and says which way a verified request was read: in the answer, and in the
 * inbound log, where the integration's card counts them.
 */
interface NotesHowItReadTheRequest
{
    /** A sentence added to the answer ('' for none). */
    public function readingNote(): string;

    /**
     * Fields the inbound log keeps beside the request's own.
     *
     * @return array<string, string>
     */
    public function logFields(): array;
}

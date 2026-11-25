<?php

declare(strict_types=1);

namespace Prosper202\User;

use RuntimeException;

/** Another save of the same account held its settings lock past ShownSettings::LOCK_WAIT. */
final class SettingsSaveBusy extends RuntimeException
{
}

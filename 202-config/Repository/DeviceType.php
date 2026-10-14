<?php

declare(strict_types=1);

namespace Prosper202\Repository;

/** A 202_device_types row, as the installer seeds them. */
enum DeviceType: int
{
    case Desktop = 1;
    case Mobile = 2;
    case Tablet = 3;
    case Bot = 4;
}

<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Video;

use Shipard\Module\Core\Exchange\Dataset\E2e\E2eException;

/** Chyba videí nad demo sadou (`shpd-ds video-run`, tasks/testing-phase7b.md); potomek {@see E2eException}, aby ji chytila stejná místa. */
class VideoException extends E2eException
{
}

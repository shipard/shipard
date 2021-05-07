<?php

namespace mac\lan\libs\alerts;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class AlertsUtils
 * @package mac\lan\libs\alerts
 */
class AlertsUtils extends Utility
{
	CONST atUnknown = 0, atWatchdogTimeout = 10, atWatchdogMissing = 11, atOSUpgrade = 20, atOutdatedSW = 21;
}


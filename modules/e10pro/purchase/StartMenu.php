<?php

namespace e10pro\purchase;


use E10\Utility, E10\utils;


/**
 * Class StartMenu
 * @package e10pro\purchase
 */
class StartMenu extends Utility
{
	var $today;
	var $teacherNdx;
	var $todayTime;
	var $todayTimeMin;
	var $todayTimeMinTeacherSchedule;

	public function create (&$content)
	{
		if (!$this->app->hasRole('purchs'))
			return;

		$wss = $this->app->webSocketServers();
		$srvidx = 0;

		forEach ($wss as $srv)
		{
			forEach ($srv['cameras'] as $camera)
			{
				if (!isset($camera['sensors']) || !count($camera['sensors']))
					continue;
				$c = '';
				$btnClass = 'camPicture e10-startMenu-tile';
				$btnParams = '';
				if (1)
				{
					$btnClass .= ' e10-trigger-action';
					$btnParams = " data-action='form' data-classid='e10pro.purchase.MobilePurchase' ";
					$btnParams .= "data-addparams='__docType=purchase&__warehouse=1";
					if (isset ($camera['sensor']))
						$btnParams .= "&__weighingMachine=".$camera['sensor'];
					$btnParams .= "'";
				}

				$c .= "<div class='$btnClass'$btnParams>";

				$sensor = $srv['sensors'][$camera['sensors'][0]];
				if ($sensor['class'] === 'number')
				{
					$c .= "<div class='e10-sensor' data-sensorid='{$sensor['ndx']}' data-serveridx='$srvidx' id='wss-{$srv['id']}-{$sensor['ndx']}'>";
					$c .= "<span class='sd' id='e10-sensordisplay-{$sensor['ndx']}'>---</span>";
					$c .= " kg";
					$c .= '</div>';
				}

				$c .= "<img id='e10-cam-{$camera['ndx']}-right' src='https://shipard.com/templates/bdc10abc-d11f23fc-257c44b5-89c5d480/img/app-icon.png'/>";
				$c .= '</div>';

				$content ['start']['items']['purchase-'.$camera['ndx']] = ['code' => $c, 'order' => 1000, 'width' => 12];
			}

			$srvidx++;
		}
	}
}

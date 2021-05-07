<?php

namespace mac\lan\libs;

use e10\Utility, \e10\utils, \e10\json, \integrations\ntf\libs\ExtNotificationContent;


/**
 * Class Alert
 * @package mac\lan\libs
 */
class Alert extends Utility
{
	public function checkAlert($requestData, &$alertData)
	{
		$alertData['systemSection'] = 169;

		$msgTextPlain = '';
		$enc = new ExtNotificationContent($this->app());

		$tableDevices = $this->app()->table('mac.lan.devices');
		$tableLans = $this->app()->table('mac.lan.lans');
		$lanRecData = NULL;

		$props = [];

		// -- device info
		$deviceRecData = NULL;
		$deviceTitle = NULL;
		if (isset($requestData['payload']['device']) && $requestData['payload']['device'])
			$deviceRecData = $tableDevices->loadItem($requestData['payload']['device']);
		if ($deviceRecData)
			$deviceTitle = $tableDevices->deviceTitleLabel($deviceRecData);
		else
			$deviceTitle = ['text' => 'Neznámé zařízení #'.$requestData['payload']['device'], 'icon' => 'icon-exclamation-triange', 'class' => 'e10-error'];

		if ($deviceTitle)
		{
			$props[] = ['p' => 'Zařízení', 'v' => $deviceTitle];
			$enc->addProperty('Zařízení', $deviceTitle['text'].' | '.$deviceTitle['suffix']);
		}

		// -- IP
		if (isset($requestData['payload']['ip']))
		{
			$props[] = ['p' => 'IP adresa', 'v' => $requestData['payload']['ip']];
			$enc->addProperty('IP adresa', $requestData['payload']['ip']);
		}

		// -- src device
		$lanNdx = 0;
		$srcDeviceRecData = NULL;
		$srcDeviceTitle = NULL;
		if (isset($requestData['payload']['srcDevice']) && $requestData['payload']['srcDevice'])
			$srcDeviceRecData = $tableDevices->loadItem($requestData['payload']['srcDevice']);
		if ($srcDeviceRecData)
		{
			$srcDeviceTitle = $tableDevices->deviceTitleLabel($srcDeviceRecData);
			$lanNdx = $srcDeviceRecData['lan'];
		}
		if ($srcDeviceTitle)
		{
			$props[] = ['p' => 'Nahlášeno z', 'v' => $srcDeviceTitle];
			$enc->addProperty('Nahlášeno z', $srcDeviceTitle['text'].' | '.$srcDeviceTitle['suffix']);
		}

		// -- LAN
		if ($lanNdx)
		{
			$lanRecData = $tableLans->loadItem($lanNdx);
			if ($lanRecData)
			{
				$props[] = ['p' => 'Síť', 'v' => $lanRecData['fullName']];
				$enc->addProperty('Síť', $lanRecData['fullName']);
			}
			if ($lanRecData && isset($lanRecData['alertsDeliveryTarget']) && $lanRecData['alertsDeliveryTarget'] !== '')
			{ // delivery to
				$existedShipardEmail = $this->app()->cfgItem ('wkf.shipardEmails.'.$lanRecData['alertsDeliveryTarget'], NULL);
			}
		}

		// -- datetime
		if (isset($requestData['payload']['time']))
		{
			$dt = new \DateTime('@'.$requestData['payload']['time']);
			$props[] = ['p' => 'Datum a čas výstrahy', 'v' => $dt->format ('Y-m-d H:i:s')];
			$enc->addProperty('Datum a čas výstrahy', $dt->format ('Y-m-d H:i:s'));
		}

		if ($requestData['alertType'] === 'mac-lan' && $requestData['alertKind'] === 'mac-lan-device-state')
		{
			$subject = '';
			$subject .= ($requestData['payload']['state'] == 2) ? '✅ ' : '🔴 ';
			$subject .= 'Zařízení je ';
			$subject .= ($requestData['payload']['state'] == 2) ? 'ZPĚT' : 'NEDOSTUPNÉ';
			$subject .= ':';
			if ($deviceTitle)
				$subject .= ' '.$deviceTitle['text'];

			if (!$deviceTitle && isset($requestData['payload']['ip']))
				$subject .= ' '.$requestData['payload']['ip'];

			$alertData['subject'] = $subject;

			$alertData['data']['title'] = ['text' => $subject, 'class' => ''];
			if ($requestData['payload']['state'] == 2)
			{
				$alertData['data']['solved'] = 1;
				$alertData['data']['title']['icon'] = 'icon-play-circle';
				$alertData['data']['title']['class'] = 'e10-row-play';
				$enc->setState(ExtNotificationContent::msSuccess);
			}
			else
			{
				$alertData['data']['priority'] = 5; // high priority
				$alertData['data']['title']['icon'] = 'icon-pause-circle';
				$alertData['data']['title']['class'] = 'e10-row-stop';
				$enc->setState(ExtNotificationContent::msError);
			}

			$msgTextPlain .= $subject;
			$enc->setTitle($subject);
		}
		elseif ($requestData['alertType'] === 'mac-lan' && $requestData['alertKind'] === 'mac-lan-netdata-alarm')
		{
			$subject = $requestData['alertSubject'];

			$alertData['subject'] = $subject;

			$alertData['data']['title'] = ['text' => $subject, 'class' => ''];
			if ($requestData['payload']['state'] == 2)
			{
				$alertData['data']['solved'] = 1;
				$alertData['data']['title']['icon'] = 'icon-play-circle';
				$alertData['data']['title']['class'] = 'e10-row-play';
				$enc->setState(ExtNotificationContent::msSuccess);
			}
			else
			{
				$alertData['data']['priority'] = 5; // high priority
				$alertData['data']['title']['icon'] = 'icon-pause-circle';
				$alertData['data']['title']['class'] = 'e10-row-stop';
				$enc->setState(ExtNotificationContent::msError);
			}

			$msgTextPlain .= $subject;
			$enc->setTitle($subject);
		}

		if (count($props))
		{
			$h = ['p' => '_Vlastnost', 'v' => 'Hodnota'];

			$alertData['data']['content'][] = [
				'type' => 'table', 'pane' => 'e10-pane-core',
				'table' => $props, 'header' => $h,
				'title' => 'Informace',
				'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
			];
		}

		$enc->setMsgTextPlain($msgTextPlain);

		$alertData['data']['extNtfContent'] = $enc->content;
	}
}

<?php

namespace Shipard\Base;


class DeviceInfo
{
	var $deviceInfo = [];
	var $browsers = [
		'Google Chrome' => ['browser' => ['icon' => 'icon-chrome', 'name' => 'Chrome']],
		'Mozilla Firefox' => ['browser' => ['icon' => 'icon-firefox', 'name' => 'Firefox']],
		'Opera' => ['browser' => ['icon' => 'icon-opera', 'name' => 'Opera']],
		'Edge' => ['browser' => ['icon' => 'icon-edge', 'name' => 'Edge']],
		'Internet Explorer' => ['browser' => ['icon' => 'icon-internet-explorer', 'name' => 'IE']],
		'Apple Safari' => ['browser' => ['icon' => 'icon-safari', 'name' => 'Safari']],
		'WebKit' => ['browser' => ['icon' => 'icon-tablet', 'name' => 'AppWebKit']],
		'Vivaldi' => ['browser' => ['icon' => 'icon-vimeo-square', 'name' => 'Vivaldi']],
	];
	var $platforms = [
			'windows' => ['os' => ['icon' => 'icon-windows', 'name' => 'Windows']],
			'mac' => ['os' => ['icon' => 'icon-apple', 'name' => 'macOS']],
			'ios' => ['os' => ['icon' => 'icon-apple', 'name' => 'iOS']],
			'chromeOS' => ['os' => ['icon' => 'icon-chrome', 'name' => 'ChromeOS']],
			'android' => ['os' => ['icon' => 'icon-android', 'name' => 'Android']],
			'linux' => ['os' => ['icon' => 'icon-linux', 'name' => 'Linux']],
			'windowsPhone' => ['os' => ['icon' => 'icon-windows', 'name' => 'Windows Phone']],
	];

	function checkDeviceInfo ($deviceInfo, $labelClass = 'label label-default')
	{
		$this->deviceInfo = ['os' => []];

		if ($deviceInfo['clientTypeId'] && $deviceInfo['clientTypeId'] && $deviceInfo['clientTypeId'] !== '')
		{
			$p = explode ('.', $deviceInfo['clientTypeId']);
			$c = explode (';', $deviceInfo['clientInfo']);
			if ($p[1] === 'cordova')
			{
				$this->deviceInfo['appLine'] = ['icon' => 'x-logo-shipard', 'suffix' => 'C', 'text' => $deviceInfo['clientVersion'], 'class' => $labelClass];
				$platform = trim(strtolower($c[2]));
				if (isset($this->platforms[$platform]))
				{
					$this->deviceInfo['os'] = $this->platforms[$platform]['os'];
					$this->deviceInfo['osLine'] = ['icon' => $this->deviceInfo['os']['icon'], 'text' => $this->deviceInfo['os']['name'], 'class' => $labelClass];
					if ($c[1] !== '')
						$this->deviceInfo['osLine']['suffix'] = $c[1];
				}
				else
				{
					$this->deviceInfo['osLine'] = ['icon' => 'system/iconCogs', 'text' => $platform, 'suffix' => '['.$platform.'] '.$deviceInfo['clientInfo'], 'class' => $labelClass];
				}
			}
			else
			{
				$ua = '';
				foreach ($c as $idx => $val)
				{
					if ($idx < 4)
						continue;
					if ($idx > 4)
						$ua .= ';';

					$ua .= $val;
				}
				$browserInfo = $this->getBrowserInfo ($ua);

				if (isset($this->browsers[$browserInfo['name']]))
				{
					$this->deviceInfo['browser'] = $this->browsers[$browserInfo['name']]['browser'];
					$this->deviceInfo['browserLine'] = ['icon' => $this->deviceInfo['browser']['icon'], 'text' => $this->deviceInfo['browser']['name'], 'suffix' => $browserInfo['version'], 'class' => $labelClass];
				}
				else
				{
					$this->deviceInfo['browserLine'] = ['icon' => 'icon-code', 'text' => $browserInfo['name'], 'suffix' => '___'.$deviceInfo['clientInfo'], 'class' => $labelClass];
				}

				if (isset($this->platforms[$browserInfo['platform']]))
				{
					$this->deviceInfo['os'] = $this->platforms[$browserInfo['platform']]['os'];
					$this->deviceInfo['osLine'] = ['icon' => $this->deviceInfo['os']['icon'], 'text' => $this->deviceInfo['os']['name'], 'class' => $labelClass];
				}
				else
				{
					$this->deviceInfo['osLine'] = ['icon' => 'system/iconCogs', 'text' => $browserInfo['platform'], 'suffix' => '___'.$deviceInfo['clientInfo'], 'class' => $labelClass];
				}
			}
		}
	}

	function getBrowserInfo ($u_agent)
	{
		$bname = 'Unknown';
		$platform = 'Unknown';
		$version= "";
		$ub = "---";

		//First get the platform?
		if (preg_match('/Windows Phone/i', $u_agent)) {
			$platform = 'windowsPhone';
		}
		elseif (preg_match('/CrOS/', $u_agent)) {
			$platform = 'chromeOS';
		}
		elseif (preg_match('/android/i', $u_agent)) {
			$platform = 'android';
		}
		elseif (preg_match('/linux/i', $u_agent)) {
			$platform = 'linux';
		}
		elseif (preg_match('/iPad|iPhone/i', $u_agent)) {
			$platform = 'ios';
		}
		elseif (preg_match('/macintosh|mac os x/i', $u_agent)) {
			$platform = 'mac';
		}
		elseif (preg_match('/windows|win32/i', $u_agent)) {
			$platform = 'windows';
		}

		// Next get the name of the useragent yes seperately and for good reason
		if(preg_match('/MSIE/i',$u_agent) && !preg_match('/Opera/i',$u_agent))
		{
			$bname = 'Internet Explorer';
			$ub = "MSIE";
		}
		elseif(preg_match('/Trident/i',$u_agent))
		{ // this condition is for IE11
			$bname = 'Internet Explorer';
			$ub = "rv";
		}
		elseif(preg_match('/Firefox/i',$u_agent))
		{
			$bname = 'Mozilla Firefox';
			$ub = "Firefox";
		}
		elseif(preg_match('/Edge/i',$u_agent))
		{
			$bname = 'Edge';
			$ub = "Edge";
		}
		elseif(preg_match('/Vivaldi/i',$u_agent))
		{
			$bname = 'Vivaldi';
			$ub = "Vivaldi";
		}
		elseif(preg_match('/OPR|Opera/i',$u_agent))
		{
			$bname = 'Opera';
			$ub = "OPR";
		}
		elseif(preg_match('/Chrome/i',$u_agent))
		{
			$bname = 'Google Chrome';
			$ub = "Chrome";
		}
		elseif(preg_match('/Safari/i',$u_agent))
		{
			$bname = 'Apple Safari';
			$ub = "Safari";
		}
		elseif(preg_match('/AppleWebKit/i',$u_agent))
		{
			$bname = 'WebKit';
			$ub = "AppleWebKit";
		}
		elseif(preg_match('/Netscape/i',$u_agent))
		{
			$bname = 'Netscape';
			$ub = "Netscape";
		}

		// finally get the correct version number
		// Added "|:"
		$known = array('Version', $ub, 'other');
		$pattern = '#(?<browser>' . join('|', $known) .
				')[/|: ]+(?<version>[0-9.|a-zA-Z.]*)#';
		if (!preg_match_all($pattern, $u_agent, $matches)) {
			// we have no matching number just continue
		}

		// see how many we have
		$i = count($matches['browser']);
		if ($i != 1) {
			//we will have two since we are not using 'other' argument yet
			//see if version is before or after the name
			if (strripos($u_agent,"Version") < strripos($u_agent,$ub)){
				$version= $matches['version'][0];
			}
			else {
				$version= $matches['version'][1];
			}
		}
		else {
			$version= $matches['version'][0];
		}

		// check if we have a number
		if ($version==null || $version=="") {$version="?";}

		return array(
				'userAgent' => $u_agent,
				'name'      => $bname,
				'version'   => $version,
				'platform'  => $platform,
				'pattern'    => $pattern
		);
	}
}


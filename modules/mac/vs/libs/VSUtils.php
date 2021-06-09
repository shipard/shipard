<?php

namespace mac\vs\libs;


class VSUtils
{
	static function camerasBar (\Shipard\Application\Application $app, $side)
	{
		if ($side === 'left')
			return '';
	
		$w = $app->workplace;
		if (!$w || !isset($w['purchaseCameras']) || !count($w['purchaseCameras']))
			return '';
	
		$c = '';
		$cameras = $app->cfgItem('mac.cameras', []);
		$servers = $app->cfgItem('mac.localServers', []);
	
		$camsNdxs = $w['purchaseCameras'];
	
		$camPicturesList = ['servers' => []];
	
		foreach ($camsNdxs as $cameraNdx)
		{
			$cam = $cameras[$cameraNdx];
			$srv = $servers[$cam['localServer']];
			$url = $srv['camerasURL'].'archive';
	
			$btnClass = 'camPicture';
			$btnParams = '';
	
			if (!isset($camPicturesList['servers'][$cam['localServer']]))
			{
				$camPicturesList['servers'][$cam['localServer']] = ['ndx' => $cam['localServer'], 'url' => $srv['camerasURL']];
			}
	
			if (1)
			{
				$btnClass .= ' e10-document-trigger';
				$btnParams = " data-action='new' data-table='e10doc.core.heads' data-addparams='__docType=purchase";
				//if (isset ($camera['sensors'][0]))
					$btnParams .= "&__weighingMachine=".'1';//$camera['sensors'][0];
				$btnParams .= "'";
	
				if ($camera['uiPlace'] === 'hidden')
					$btnParams .= " style='display: none;'";
			}
	
			$c .= "<button class='$btnClass'$btnParams>" .
				"<img id='e10-cam-{$cam['ndx']}-$side' src='{$app->urlRoot}/www-root/sc/shipard/ph-image-1920-1080.svg'/>" .
				'</button>';
		}
	
		$mainCode = '';
	
		if ($side === 'right')
			$mainCode .= "<div style='margin-bottom: .9ex;' id='big-clock'>12:00</div>";
	
		$mainCode .= "<div class='camPicts'>";
	
		$mainCode .= $c;
	
		if ($side === 'right')
		{
			$mainCode .= "<script language='JavaScript' type='text/javascript'>\n";
			$mainCode .= "var g_appWindowsCamerasPictures = ".json_encode($camPicturesList).";\n";
			$mainCode .= "$(camerasReload ()); bigClock();\n";
			$mainCode .= "</script>";
		}
		$mainCode .= '</div>';
	
		return $mainCode;
	}
	
	static function camerasBarRight ($app)
	{
		return self::camerasBar ($app, 'right');
	}

	static function camerasBarLeft ($app)
	{
		return self::camerasBar ($app, 'left');
	}	
}
<?php

namespace mac\vs\libs;
use \Shipard\Utils\Utils;


/**
 * class VSUtils
 */
class VSUtils
{
	static function camerasBar (\Shipard\Application\Application $app, $side)
	{
		$streams = $app->cfgItem('terminal.webGo2RtcCams', NULL);
		if ($streams)
			return self::camerasBarGo2RTC ($app, $side);

		$streams = $app->cfgItem('terminal.webRtcCams', NULL);
		if ($streams)
			return self::camerasBarRTC ($app, $side);

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
			$mainCode .= "$(camerasReload (1)); bigClock();\n";
			$mainCode .= "</script>";
		}
		$mainCode .= '</div>';

		return $mainCode;
	}

	static function camerasBarRTC (\Shipard\Application\Application $app, $side)
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

		$streams = $app->cfgItem('terminal.webRtcCams');

		foreach ($camsNdxs as $cameraNdx)
		{
			$cam = $cameras[$cameraNdx];
			$srv = $servers[$cam['localServer']];

			$btnClass = 'camPicture';
			$btnParams = '';

			if (!isset($camPicturesList['servers'][$cam['localServer']]))
			{
				$camPicturesList['servers'][$cam['localServer']] = ['ndx' => $cam['localServer'], 'url' => $srv['camerasURL']];
			}

			$btnClass .= ' e10-document-trigger';
			$btnParams = " data-action='new' data-table='e10doc.core.heads' data-addparams='__docType=purchase";
			$btnParams .= "&__weighingMachine=".'1';//$camera['sensors'][0];
			$btnParams .= "'";

			$c .= "<button class='$btnClass'$btnParams style='background-color: transparent;'>";

			$c .= "<video id='e10-cam-{$cam['ndx']}-right' autoplay muted playsinline ";
			$c .= " style='width: 100%; display: block;' ";
			$c .= " data-stream-url='".$streams[$cam['ndx']]."'>";

			$c .=	'</button>';
		}

		$mainCode = '';

		if ($side === 'right')
			$mainCode .= "<div style='margin-bottom: .9ex;' id='big-clock'>12:00</div>";

		$mainCode .= "<div class='camPicts'>";

		$mainCode .= $c;

		if ($side === 'right')
		{
			$mainCode .= "
			<script>
			document.addEventListener('DOMContentLoaded', function () {
				function startPlay (videoEl) {
					const url = videoEl.getAttribute('data-stream-url');
					const webrtc = new RTCPeerConnection({
						iceServers: [{
							urls: ['stun:stun.l.google.com:19302']
						}],
						sdpSemantics: 'unified-plan'
					})
					webrtc.ontrack = function (event) {
						console.log(event.streams.length + ' track is delivered')
						videoEl.srcObject = event.streams[0]
						videoEl.play()
					}
					webrtc.addTransceiver('video', { direction: 'sendrecv' })
					webrtc.onnegotiationneeded = async function handleNegotiationNeeded () {
						const offer = await webrtc.createOffer()

						await webrtc.setLocalDescription(offer)

						fetch(url, {
							method: 'POST',
							body: new URLSearchParams({ data: btoa(webrtc.localDescription.sdp) })
						})
							.then(response => response.text())
							.then(data => {
								try {
									webrtc.setRemoteDescription(
										new RTCSessionDescription({ type: 'answer', sdp: atob(data) })
									)
								} catch (e) {
									console.warn(e)
								}
							})
					}

					const webrtcSendChannel = webrtc.createDataChannel('rtsptowebSendChannel')
					webrtcSendChannel.onopen = (event) => {

						webrtcSendChannel.send('ping')
					}
					webrtcSendChannel.onclose = (_event) => {

						startPlay(videoEl, url);
					}
					webrtcSendChannel.onmessage = event => console.log(event.data)
				}
			";

			foreach ($camsNdxs as $cameraNdx)
			{
				$mainCode .= "const videoEl{$cameraNdx} = document.querySelector('#e10-cam-{$cameraNdx}-right');\n";
				$mainCode .= "startPlay(videoEl{$cameraNdx});\n\n";
			}

			$mainCode .= "			});\n			\n</script>\n";

		}
		$mainCode .= '</div>';

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

	static function camerasBarGo2RTC (\Shipard\Application\Application $app, $side)
	{
		if ($side === 'left')
			return '';

		$w = $app->workplace;
		if (!$w || !isset($w['purchaseCameras']) || !count($w['purchaseCameras']))
			return '';

		$c = '';

		$scRoot = $app->scRoot();
		$c .= "<script type=\"text/javascript\" src=\"{$scRoot}/libs/js/go2rtc/video-rtc.js?v=14\"></script>\n";
		$c .= "<script type=\"text/javascript\" src=\"{$scRoot}/libs/js/go2rtc/video-stream.js?v=14\"></script>\n";

		$cameras = $app->cfgItem('mac.cameras', []);
		$servers = $app->cfgItem('mac.localServers', []);

		$camsNdxs = $w['purchaseCameras'];

		$camPicturesList = ['servers' => []];

		$streams = $app->cfgItem('terminal.webGo2RtcCams');

		foreach ($camsNdxs as $cameraNdx)
		{
			$cam = $cameras[$cameraNdx];
			$srv = $servers[$cam['localServer']];

			$btnClass = 'camPicture';
			$btnParams = '';

			if (!isset($camPicturesList['servers'][$cam['localServer']]))
			{
				$camPicturesList['servers'][$cam['localServer']] = ['ndx' => $cam['localServer'], 'url' => $srv['camerasURL']];
			}

			$btnClass .= ' e10-document-trigger';
			$btnParams = " data-action='new' data-table='e10doc.core.heads' data-addparams='__docType=purchase";
			$btnParams .= "&__weighingMachine=".'1';//$camera['sensors'][0];
			$btnParams .= "'";

			$te = new \mac\admin\libs\TokensEngine($app);
			$validTokens = $te->loadLANValidTokens($srv['lan']);

			$snaphotBaseFileName = 'img-'.$srv['ndx'].'-'.$cam['ndx'].'-'.base_convert(time(), 10, 36).Utils::createToken(6).'.jpg';
			$snapshotUrl = 'https://'.$srv['fqdn'].':'.$srv['httpsPort'].'/'.$validTokens[0].'/camera-snapshot'.'/'.$cam['ndx'].'/'.$snaphotBaseFileName;
			$btnParams .= " data-add-snapshot-url='{$snapshotUrl}'";

			$c .= "<button class='$btnClass'$btnParams style='background-color: transparent;'>";

			$c .= "<div id='e10-cam-{$cam['ndx']}-right'  ";
			$c .= " style='width: 100%; display: block;' ";
			$c .= " data-stream-url='".$streams[$cam['ndx']]."'";
			$c .= '></div>';
			$c .=	'</button>';
		}

		$mainCode = '';

		if ($side === 'right')
			$mainCode .= "<div style='margin-bottom: .9ex;' id='big-clock'>12:00</div>";

		$mainCode .= "<div class='camPicts'>";

		$mainCode .= $c;

		if ($side === 'right')
		{
			$mainCode .= "
        <style>
        video-stream {
            position: relative;
            >.info {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                padding: 12px;
                color: white;
                display: flex;
                justify-content: space-between;
                pointer-events: none;
            }
        }
        </style>
			<script>
			function startVideoGO2RTC (videoEl)
			{
				const streamUrl = videoEl.getAttribute('data-stream-url');
				const video = document.createElement('video-stream');
				video.src = new URL(streamUrl);
				video.media = 'video';
				videoEl.appendChild(video);
			}
			document.addEventListener('DOMContentLoaded', function () {;
			";

			foreach ($camsNdxs as $cameraNdx)
			{
				$mainCode .= "const videoEl{$cameraNdx} = document.querySelector('#e10-cam-{$cameraNdx}-right');\n";
				$mainCode .= "startVideoGO2RTC(videoEl{$cameraNdx});\n\n";
			}

			$mainCode .= "})\n\n</script>\n";
		}
		$mainCode .= '</div>';

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

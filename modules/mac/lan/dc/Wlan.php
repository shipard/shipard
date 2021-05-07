<?php

namespace mac\lan\dc;

use e10\utils, e10\json;


/**
 * Class Wlan
 * @package mac\lan\dc
 */
class Wlan extends \e10\DocumentCard
{
	public function createContentBody ()
	{
		$s = ':;"';
		$qrCodeData = 'WIFI:S:'.addcslashes($this->recData['ssid'], $s).';T:WPA;P:'.addcslashes($this->recData['wpaPassphrase'], $s).';;';
		$qrCodeGenerator = new \lib\tools\qr\QRCodeGenerator($this->app);
		$qrCodeGenerator->textData = $qrCodeData;
		$qrCodeGenerator->createQRCode();
		$fn = $qrCodeGenerator->url;

		$c = '';
		$c .= "
<table class='fullWidth'>
		<tr>
				<td style='text-align: left;'>
						<i class='fa fa-wifi' style='font-size: 10em'></i><br>
				</td>
				<td style='font-size: 130%; text-align: center;'>
						<b>ssid</b><br>
						".utils::es($this->recData['ssid'])."<br>
						<br>
						<b>heslo</b><br>
						".utils::es($this->recData['wpaPassphrase'])."<br>
				</td>
				<td style='text-align: right;'>
						<img src='{$fn}' style='height: 11em;'>
				</td>
		</tr>
</table>
		";

		// -- data
		$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'line', 'line' => ['code' => $c]]);
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}

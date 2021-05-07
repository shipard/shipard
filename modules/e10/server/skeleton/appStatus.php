<?php


class pageStatus
{
	var $hostingInfo;

	public function pageBegin ()
	{
		$c = "
<!DOCTYPE HTML>
<html lang=\"cs\">
<head>
	<title>Nastavení</title>
	<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\"/>";

		$c .= $this->styles();

		$c .= "
		</head>
<body>
	";

		return $c;
	}

	public function pageEnd ()
	{
		$c = "
</body>
</html>
	";

		return $c;
	}

	public function styles ()
	{
		$c = "
			<style>
				body {
					background-color: aliceblue; font-family: sans-serif;
					text-align: center;
					line-height: 1.6em;
					}
				h1 {padding-top: 10%;}
				hr {margin-top: 20px;
					margin-bottom: 20px;
					border: 0; width: 80%;
					border-top: 1px solid #999;}
			</style>
		";

		return $c;
	}

	public function supportFooter ()
	{
		/*
		$hostingPhone = isset ($this->hostingInfo['hostingPhone']) ? $this->hostingInfo['hostingPhone'] : '+420 774 020 522';
		$hostingEmail = isset ($this->hostingInfo['hostingEmail']) ? $this->hostingInfo['hostingEmail'] : 'podpora@shipard.cz';
		$c = 'Kontakt na technickou podporu:<br/>'.
				 "telefon: $hostingPhone | email: <a href='mailto:$hostingEmail'>$hostingEmail</a>";

		return $c;
		*/

		return '';
	}

	public function stop ()
	{
		ob_start();

		header('HTTP/1.1 503 Service Unavailable');

		$c = '';
		$c .= $this->pageBegin();
		$c .= '<h1>Databáze je zastavena</h1><hr/>'.$this->supportFooter();
		$c .= $this->pageEnd();

		echo $c;

		ob_flush();

		return TRUE;
	}

	public function demo ()
	{
		ob_start();

		$progressData = json_decode(@file_get_contents('tmp/demoMakeHistoryProgress.json'), TRUE);

		$c = '';
		$c .= $this->pageBegin();
		$c .= '<h1>Vytváří se demonstrační data</h1>';
		$c .=	'Až bude vše hotovo, prohlížeč se sám přepne zpět do aplikace.'.'<br/>';
		$c .=	'<hr/>';

		if ($progressData)
		{
			$c .=	'<br/>';
			$c .=	'<br/>';
			$c .= "<progress style='width: 40%;' value='{$progressData['countNow']}' max='{$progressData['countAll']}'> </progress>";
			$c .=	'<br/>';
			$c .=	'Odhadovaný počet minut do konce: '.$progressData['eta'];
			$c .=	'<br/>';
			$c .=	'<br/>';
		}
		else
		{
			$c .=	'<br/>';
			$c .=	'<br/>';
			$c .=	'<em>Bude to chvíli trvat...</em><br/>';
			$c .=	'<br/>';
			$c .=	'<br/>';
		}

		$c .=	'<hr/>'.$this->supportFooter();

		$c .= "
				<script>
				function pageRefresh() {
					location.reload(true);
				}
				setTimeout(pageRefresh, 10000);
				</script>";
		$c .= $this->pageEnd();

		echo $c;

		ob_flush();

		return TRUE;
	}

	public function reset ()
	{
		ob_start();

		$c = '';
		$c .= $this->pageBegin();
		$c .= '<h1>Připravuje se nová databáze</h1>
					<hr/>
					<em>Bude to trvat přibližně 2 minuty.</em><br/>
					Až bude vše hotovo, prohlížeč se sám přepne zpět do aplikace.
					<br/>
					<hr/>'.$this->supportFooter();

		$c .= "
				<script>
				function pageRefresh() {
					location.reload(true);
				}
				setTimeout(pageRefresh, 5000);
				</script>";
		$c .= $this->pageEnd();

		echo $c;

		ob_flush();

		return TRUE;
	}

	public function show ()
	{
		$status = file_get_contents('config/status.data');

		//$this->hostingInfo = json_decode(file_get_contents('/etc/e10---hosting.cfg'), TRUE);

		switch ($status)
		{
			case 'DEMO': return $this->demo();
			case 'RESET': return $this->reset();
			case 'STOP': return $this->stop();
		}
	}
}

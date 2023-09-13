<?php

namespace e10pro\zus\libs\ezk;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use \e10pro\zus\zusutils;
use \Shipard\Report\GlobalReport;


/**
 * class ReportStudentsActivations
 */
class ReportStudentsActivations extends GlobalReport
{
	var $list = [];

	function init ()
	{
		set_time_limit (3000);

		// -- toolbar
		$this->addParam ('switch', 'ucitel', ['title' => 'Učitel', 'switch' => zusutils::ucitele($this->app, TRUE)]);

		parent::init();

		$this->setInfo('icon', 'reportPrintCertificates');
		$this->setInfo('title', 'Aktivační odkazy pro EŽK studentů');
	}

	function createContent ()
	{
		parent::createContent();
		$this->loadList();

		if ($this->reportParams ['ucitel']['value'])
			$this->setInfo('param', 'Učitel', $this->reportParams ['ucitel']['activeTitle']);

		$h = ['name' => 'Jméno', 'url' => 'Odkaz pro aktivaci', 'img' => 'QR kód', ];

		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $this->list]);
	}

	protected function loadList()
	{
    $ucitelNdx = intval($this->reportParams ['ucitel']['value']);

    $schoolYear = zusutils::aktualniSkolniRok();
		$q = [];
		array_push($q, 'SELECT studium.*');
		array_push($q, ' FROM [e10pro_zus_studium] as studium ');
		array_push($q, ' WHERE [stav] = %i', 1200);
		array_push($q, ' AND skolniRok = %s', $schoolYear);
    array_push($q, ' ORDER BY [ucitel], [nazev]');

    $studentsNdxs = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      $existedUser = $this->db()->query('SELECT * FROM [e10_users_users] WHERE [person] = %i', $r['student'])->fetch();
      if (!$existedUser)
        continue;

      $existedRequest = $this->db()->query('SELECT * FROM [e10_users_requests] WHERE [user] = %i', $existedUser['ndx'])->fetch();
      if (!$existedRequest)
        continue;

      if (in_array($r['student'], $studentsNdxs))
        continue;

      $studentsNdxs[] = $r['student'];

      if ($ucitelNdx && $ucitelNdx !== $r['ucitel'])
        continue;

      $sendRequestEngine = new \e10\users\libs\SendRequestEngine($this->app());
      $sendRequestEngine->setRequestNdx($existedRequest['ndx']);

      $qrCodeData = $sendRequestEngine->requestUrl();
      $qrCodeGenerator = new \lib\tools\qr\QRCodeGenerator($this->app);
      $qrCodeGenerator->textData = $qrCodeData;
      $qrCodeGenerator->createQRCode();
      $fn = $qrCodeGenerator->url;

      $item = [
        'name' => $existedUser['fullName'],
        'url' => [
          ['text' => 'Odkaz pro aktivaci:', 'class' => 'block'],
          ['text' => $sendRequestEngine->requestUrl(), 'class' => 'block'],

          ['text' => ' ', 'class' => 'block'],
          ['text' => 'Přihlašovací jméno: '.$existedUser['login'], 'class' => 'block'],

        ],
        'img' => ['code' => "<img src='$fn' style='width: 5em;'>"],
        '_options' => ['afterSeparator' => 'separator', 'beforeSeparator' => 'separator'],
      ];

      $this->list[] = $item;
    }
	}
}

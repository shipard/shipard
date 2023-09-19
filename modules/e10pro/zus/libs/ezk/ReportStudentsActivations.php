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
    $this->addParam('switch', 'createdType', ['title' => 'Vytvořeno',
      'switch' => ['all' => 'Vše', '1' => 'Za poslední den', '7' => 'týden'],
      'radioBtn' => 1, 'defaultValue' => '1'
    ]);

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
    $createdType = $this->reportParams ['createdType']['value'];
    $lastTeacherNdx = 0;

    if ($createdType == '7')
      $createLimit = new \DateTime('7 days ago');
    else
      $createLimit = new \DateTime('1 day ago');

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
      if ($existedRequest['requestState'] > 2)
        continue;
      if ($createdType !== 'all' && $existedRequest ['tsCreated'] < $createLimit)
        continue;

      if (in_array($r['student'], $studentsNdxs))
        continue;

      $studentsNdxs[] = $r['student'];

      if ($ucitelNdx && $ucitelNdx !== $r['ucitel'])
        continue;

      if ($createdType !== 'all' && $lastTeacherNdx !== $r['ucitel'])
      {
        $teacherRecData = $this->app()->loadItem($r['ucitel'], 'e10.persons.persons');
        $item = [
          'name' => $teacherRecData['fullName'],
          '_options' => [
            'afterSeparator' => 'separator', 'beforeSeparator' => 'separator', 'class' => 'subheader',
            'colSpan' => ['name' => 3]
          ],
        ];
        if ($lastTeacherNdx)
          $item['_options']['class'] .= ' pageBreakBefore';
        $this->list[] = $item;
      }


      $lastTeacherNdx = $r['ucitel'];

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

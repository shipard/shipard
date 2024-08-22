<?php
namespace e10pro\soci\libs;

use e10\utils as E10Utils;
use \Shipard\Utils\Utils, \Shipard\Utils\Json, \Shipard\Utils\Str;
use \e10\base\libs\UtilsBase;


class WebFormEntry extends \Shipard\Base\WebForm2
{
	var $valid = FALSE;
	var $pobocky;
	var $oddeleni;
	var $spamScore = '';

	public function fields ()
	{
		return [
			'firstName', 'lastName',
      'bdDay', 'bdMonth', 'bdYear',
      'place', 'event',
			'email', 'phone',
			'healthIssues',
			'healthIssuesNote',
			'bdDay', 'bdMonth', 'bdYear',
			'paymentPeriod',
			'saleType',
		];
	}

	public function validate ()
	{
		$this->valid = TRUE;

		$this->checkBirthdate();
		$this->checkValidField('firstName', 'Jméno není vyplněno');
		$this->checkValidField('lastName', 'Příjmení není vyplněno');
		$this->checkValidField('event', 'Vyberte prosím kurz');

		$reCaptchaResponse = $this->app->testPostParam ('webFormReCaptchtaResponse', NULL);
		if ($reCaptchaResponse !== NULL)
		{
			if ($reCaptchaResponse === '')
			{
				$this->formErrors ['msg'] = 'Odeslání formuláře se nezdařilo.';
				return FALSE;
			}

			$validateUrl = 'https://www.google.com/recaptcha/api/siteverify?secret='.$this->template->pageParams['recaptcha-v3-secret-key'].'&response='.$reCaptchaResponse.'&remoteip='.$_SERVER ['REMOTE_ADDR'];
			$validateResult =  \E10\http_post ($validateUrl, '');
			$validateResultData = json_decode($validateResult['content'], TRUE);
			if ($validateResultData && isset($validateResultData['success']))
			{
				if ($validateResultData['success'])
				{
					if ($validateResultData['score'] < 0.5)
					{
						$this->formErrors ['msg'] = 'Vaše zpráva bohužel vypadá jako SPAM.';
						return FALSE;
					}
					$this->spamScore = strval($validateResultData['score']);
				}
				else
				{
					$this->formErrors ['msg'] = 'Odeslání formuláře se nezdařilo.';
					return FALSE;
				}
			}
		}

		return $this->valid;
	}

	protected function checkBirthdate()
	{
		$bdStr = sprintf('%04d-%02d-%02d', intval($this->data['bdYear']), intval($this->data['bdMonth']), intval($this->data['bdDay']));
		//$bd = new \DateTime($bdStr);
		if (!Utils::dateIsValid($bdStr))
		{
			$this->formErrors ['bdDay'] = 'Vyplňte prosím správné datum narození';
			$this->valid = FALSE;
			return FALSE;
		}
	}

	public function checkValidField ($id, $msg)
	{
		if ($this->app->testPostParam ($id) == '')
		{
			$this->formErrors [$id] = $msg;
			$this->valid = FALSE;
		}
	}

	public function doIt ()
	{
		/** @var \e10pro\soci\TableEntries $tableEntries */
		$tableEntries = $this->app->table('e10pro.soci.entries');

		$bd = NULL;
		$bdStr = sprintf('%04d-%02d-%02d', intval($this->data['bdYear']), intval($this->data['bdMonth']), intval($this->data['bdDay']));
		if (Utils::dateIsValid($bdStr))
			$bd = new \DateTime($bdStr);

		$newEntry = [
			'firstName' => Str::upToLen($this->data['firstName'], 60),
      'lastName' => Str::upToLen($this->data['lastName'], 80),
      'email' => Str::upToLen($this->data['email'], 60),
      'phone' => Str::upToLen($this->data['phone'], 60),

      'entryTo' => intval($this->data['event']),
      'entryState' => 1,
      'entryKind' => 1,
      'entryPeriod' => 2,

      'dateIssue' => Utils::today(),
      'webSentDate' => new \DateTime(),

			'birthday' => $bd,
			'paymentPeriod' => intval($this->data['paymentPeriod'] ?? 0),
			'saleType' => intval($this->data['saleType'] ?? 0),
			'source' => 2, // web form

			'docState' => 1000, 'docStateMain' => 0,
		];

		$newNdx = $tableEntries->dbInsertRec($newEntry);
		$tableEntries->docsLog($newNdx);

		return TRUE;
	}

	public function successMsg ()
	{
		return $this->dictText('Hotovo. Během několika minut Vám pošleme e-mail s potvrzením.');
	}

  protected function loadData()
  {
		$this->checkFormParamsList('docKinds', TRUE);
		$this->checkFormParamsList('withLabels');
		$this->checkFormParamsList('withoutLabels');
    $this->checkFormParamsList('places', TRUE);
		$this->checkFormParamsList('periods', TRUE);

    // -- woEvents
		/** @var \e10\persons\TableAddress */
		$tableAddress = $this->app()->table('e10.persons.address');

		/** @var \e10\base\TablePlaces */
		$tablePlaces = $this->app()->table('e10.base.places');

		$q = [];
		array_push ($q, 'SELECT wo.*,');
		array_push ($q, ' places.fullName AS placeFullName, places.shortName AS placeShortName, places.id AS placeId');
		array_push ($q, ' FROM [e10mnf_core_workOrders] AS wo');
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON wo.place = places.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND wo.docState IN %in', [1200, 8000]);

		if (isset($this->formParams['withLabels']) && count($this->formParams['withLabels']))
		{
			array_push ($q, ' AND EXISTS (',
				'SELECT ndx FROM e10_base_clsf WHERE wo.ndx = recid AND tableId = %s', 'e10mnf.core.workOrders',
				' AND [clsfItem] IN %in', $this->formParams['withLabels'],
				')');
		}
		if (isset($this->formParams['withoutLabels']) && count($this->formParams['withoutLabels']))
		{
			array_push ($q, ' AND NOT EXISTS (',
				'SELECT ndx FROM e10_base_clsf WHERE wo.ndx = recid AND tableId = %s', 'e10mnf.core.workOrders',
				' AND [clsfItem] IN %in', $this->formParams['withoutLabels'],
				')');
		}

    if (isset($this->formParams['docKinds']))
      array_push ($q, ' AND wo.docKind IN %in', $this->formParams['docKinds']);

		if (isset($this->formParams['places']))
      array_push ($q, ' AND wo.place IN %in', $this->formParams['places']);

		if (isset($this->formParams['periods']))
      array_push ($q, ' AND wo.usersPeriod IN %in', $this->formParams['periods']);


		array_push ($q, ' ORDER BY wo.[title], wo.[ndx]');

		$t = [];
		$pks = [];
    $places = [];

		$activePlace = $this->app()->testPostParam('place', NULL);
		$activeEvent = $this->app()->testPostParam('event', NULL);

		$rows = $this->app()->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
        'ndx' => $r['ndx'],
				'title' => $r['title'],
				'place' => [
					'fullName' => $r['placeFullName'],
					'shortName' => $r['placeShortName'],
					'id' => $r['placeId'],
          'ndx' => $r['place'],
				],
				'prices' => [],
      ];

			$linkedPersons = UtilsBase::linkedPersons ($this->app(), 'e10mnf.core.workOrders', $r['ndx']);
			foreach ($linkedPersons as $wkNdx => $lp)
			{
				if (isset($lp['e10mnf-workRecs-admins']))
					$item['lp']['admins'] = $lp['e10mnf-workRecs-admins'];
			}

			$this->getEventPrices($r, $item['prices']);
			$item['prices'] = array_values($item['prices']);

			if ($activeEvent !== NULL && $r['ndx'] == intval($activeEvent))
				$item['selected'] = 1;

			$item['place']['address'] = $tableAddress->loadAddresses($tablePlaces, $r['place']);
			if ($activePlace !== NULL && $r['place'] == intval($activePlace))
				$item['place']['selected'] = 1;

      $vdsData = Json::decode($r['vdsData']);
      if ($vdsData)
      {
				$beginOrder = '';
				if (isset($vdsData['weekDay']))
					$beginOrder .= $vdsData['weekDay'];
				if (isset($vdsData['startTime']))
					$beginOrder .= $vdsData['startTime'];

				$item['beginOrder']	= $beginOrder;
        $item['data'] = $vdsData;

        if (isset($vdsData['publicEmail']) && $vdsData['publicEmail'] !== '')
          $item['email'] = $vdsData['publicEmail'];
      }

			// -- capacity
			$qcp = [];
			array_push($qcp, 'SELECT entryTo, COUNT(*) AS cnt');
			array_push($qcp, ' FROM e10pro_soci_entries AS entries');
			array_push($qcp, ' WHERE entryTo = %i', $r['ndx']);
			array_push($qcp, ' AND docState IN %in', [1000, 4000, 8000]);
			array_push($qcp, ' GROUP BY 1');
			$cpRows = $this->app()->db()->query($qcp);
			foreach ($cpRows as $cpr)
			{
				$capacity = intval($item['data']['capacity'] ?? 0);
				$item['cntEntries'] = $cpr['cnt'];
				$item['eventCapacity'] = $capacity;
				if ($capacity && $cpr['cnt'] >= $capacity)
					$item['overCapacity'] = 1;
			}

			$t[$r['ndx']] = $item;
			$pks[] = $r['ndx'];

      if (!isset($places[$r['place']]))
      {
        $places[$r['place']] = $item['place'];
      }
		}

		$this->formInfo['events'] = array_values($t);
    $this->formInfo['places'] = array_values($places);

		$activeBdDay = $this->app()->testPostParam('bdDay', NULL);
		$this->formInfo['bdDays'] = [];
		for ($i = 1; $i <= 31; $i++)
		{
			$item = ['day' => $i];
			if ($activeBdDay !== NULL && $i == intval($activeBdDay))
				$item['selected'] = 1;

			$this->formInfo['bdDays'][] = $item;
		}

		$activeBdMonth = $this->app()->testPostParam('bdMonth', NULL);
		$this->formInfo['bdMonths'] = [];
		for ($i = 1; $i <= 12; $i++)
		{
			$item = ['monthNum' => $i, 'monthName' => Utils::$monthNames[$i - 1]];
			if ($activeBdMonth !== NULL && $i == intval($activeBdMonth))
				$item['selected'] = 1;
			$this->formInfo['bdMonths'][] = $item;
		}

		$activeBdYear = $this->app()->testPostParam('bdYear', NULL);
		$this->formInfo['bdYears'] = [];
		for ($i = 1935; $i <= 2020; $i++)
		{
			$item = ['year' => $i];
			if ($activeBdYear !== NULL && $i == intval($activeBdYear))
				$item['selected'] = 1;
			$this->formInfo['bdYears'][] = $item;
		}

		$activePaymentPeriod = $this->app()->testPostParam('paymentPeriod', NULL);
		if ($activePaymentPeriod !== NULL)
			$this->formInfo['paymentPeriod_'.intval($activePaymentPeriod)] = 1;

		$activeSaleType = $this->app()->testPostParam('saleType', NULL);
		if ($activeSaleType !== NULL)
			$this->formInfo['saleType_'.intval($activeSaleType)] = 1;
	}

	protected function getEventPrices($eventRecData, &$dest)
	{
		$q = [];
		array_push($q, 'SELECT [ei].* ');
		array_push($q, ' FROM [e10pro_soci_entriesInvoicing] AS [ei]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [entryKind] = %i', 1);
		array_push($q, ' AND ([entryTo] = %i', 0, ' OR [entryTo] = %i', $eventRecData['ndx'], ')');
		array_push($q, ' ORDER BY [entryTo] DESC, [order]');
		$rows = $this->app()->db()->query($q);
		foreach ($rows as $r)
		{
			$priceId = $r['paymentPeriod'].'-'.$r['saleType'];
			if (isset($dest[$priceId]))
				continue;
			$dest[$priceId] = ['priceId' => $priceId, 'price' => $r['priceAll']];
		}
	}
}

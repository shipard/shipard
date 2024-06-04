<?php
namespace e10pro\soci\libs;
use \Shipard\Utils\Utils, \Shipard\Utils\Json, \Shipard\Utils\Str;


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
      'branch', 'course',
			'email', 'phone',
			'healthIssues', 'healthIssuesNote',
		];
	}

	public function validate ()
	{
		$this->valid = TRUE;

		$this->checkValidField('firstName', 'Jméno není vyplněno');
		$this->checkValidField('lastName', 'Příjmení není vyplněno');

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

	public function checkValidField ($id, $msg)
	{
		if ($id === 'misto')
		{
			$misto = intval($this->app->testPostParam ('misto'));
			if (!$misto)
			{
				$this->formErrors [$id] = $msg;
				$this->valid = FALSE;
				return;
			}
		}
		if ($id === 'svpObor')
		{
			$svpObor = intval($this->app->testPostParam ('svpObor'));
			if (!$svpObor)
			{
				$this->formErrors [$id] = $msg;
				$this->valid = FALSE;
				return;
			}
		}
		if ($id === 'svpOddeleni')
		{
			$svpOddeleni = intval($this->app->testPostParam ('svpOddeleni'));
			if (!$svpOddeleni)
			{
				$this->formErrors [$id] = $msg;
				$this->valid = FALSE;
				return;
			}
		}

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

		$newEntry = [
			'firstName' => Str::upToLen($this->data['firstName'], 60),
      'lastName' => Str::upToLen($this->data['lastName'], 80),
      'email' => Str::upToLen($this->data['email'], 60),
      'phone' => Str::upToLen($this->data['phone'], 60),

      'entryTo' => intval($this->data['course']),
      'entryState' => 1,
      'entryKind' => 1,
      'entryPeriod' => 2,

      'dateIssue' => Utils::today(),
      'webSentDate' => new \DateTime(),

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
				]
      ];
			$item['place']['address'] = $tableAddress->loadAddresses($tablePlaces, $r['place']);

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

			$t[$r['ndx']] = $item;
			$pks[] = $r['ndx'];

      if (!isset($places[$r['place']]))
      {
        $places[$r['place']] = $item['place'];
      }
		}

		$this->formInfo['events'] = array_values($t);
    $this->formInfo['places'] = array_values($places);
  }
}

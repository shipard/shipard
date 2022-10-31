<?php

namespace e10\persons;
use \E10\DbTable, e10\TableForm, e10\str;
use \Shipard\Utils\World;

/**
 * Class TableAddress
 * @package e10\persons
 */
class TableAddress extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.persons.address', 'e10_persons_address', 'Adresy');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
		$recData['country'] = World::countryId($this->app(), $recData['worldCountry']);

		if (!isset ($recData['docState']) || $recData['docState'] == 0)
		{
			$recData['docState'] = 4000;
			$recData['docState'] = 2;
		}
	}

	public function columnRefInputTitle ($form, $srcColumnId, $inputPrefix)
	{
		$pk = isset ($form->recData [$srcColumnId]) ? $form->recData [$srcColumnId] : 0;
		if (!$pk)
			return '';

		$recData = $this->loadItem($pk);
		if (!$recData)
			return '';

		$refTitle = [];
		if ($recData['street'] !== '')
			$refTitle[] = ['text' => $recData['street']];
		if ($recData['city'] !== '')
			$refTitle[] = ['text' => $recData['city']];
		if ($recData['zipcode'] !== '')
			$refTitle[] = ['text' => $recData['zipcode']];

		return $refTitle;
	}

	public function geoCode ($recData)
	{
		$googleMapsApiKey = $this->app()->cfgServer['googleMapsApiKey'] ?? '';
		if ($googleMapsApiKey === '')
			return FALSE;

		$locHash = $this->geoCodeLocHash($recData);
		$logEvent = ['tableid' => $recData['tableid'], 'recid' => $recData['recid'], 'eventType' => 3];

		if ($recData['street'] === '' && $recData['city'] === '' && $recData['zipcode'] === '')
		{
			$rec = [ 'lat'=>0, 'lon'=>0, 'locState' => 2, 'locTime' => new \DateTime(), 'locHash' => $locHash];
			$this->db()->query ("UPDATE [e10_persons_address] SET ", $rec, ' WHERE ndx = %i', $recData['ndx']);
			return TRUE;
		}

		$addressParam = urlencode($recData['street'].', '.$recData['city'].' '.$recData['zipcode'].', '.$recData['country']);
		$logEvent['eventSubtitle'] = str::upToLen('GPS: '.$recData['street'].', '.$recData['city'].' '.$recData['zipcode'].', '.$recData['country'], 130);

		$url = 'https://maps.googleapis.com/maps/api/geocode/json?address='.$addressParam.'&key='.$googleMapsApiKey;

		$opts = ['http'=> ['timeout' => 1, 'method'=>'GET', 'header'=> "Connection: close\r\n"]];
		$context = stream_context_create($opts);
		$resultString = file_get_contents ($url, FALSE, $context);

		if (!$resultString)
		{
			$logEvent['eventResult'] = 3;
			$this->app()->addLogEvent($logEvent);
			return FALSE;
		}
		$logEvent['eventData'] = $resultString;
		$resultData = json_decode ($resultString, TRUE);

		if ($resultData['status'] === 'OK')
		{
			$rec = [
					'lat'=>$resultData['results'][0]['geometry']['location']['lat'],
					'lon'=>$resultData['results'][0]['geometry']['location']['lng'],
					'locState' => 1, 'locTime' => new \DateTime(), 'locHash' => $locHash
			];
			$this->db()->query ('UPDATE [e10_persons_address] SET ', $rec, ' WHERE ndx = %i', $recData['ndx']);
			$logEvent['eventResult'] = 1;
			$this->app()->addLogEvent($logEvent);
			return TRUE;
		}

		if ($resultData['status'] === 'ZERO_RESULTS')
		{
			$rec = [ 'lat'=>0, 'lon'=>0, 'locState' => 2, 'locTime' => new \DateTime(), 'locHash' => $locHash];
			$this->db()->query ('UPDATE [e10_persons_address] SET ', $rec, ' WHERE ndx = %i', $recData['ndx']);
			$logEvent['eventResult'] = 2;
			$this->app()->addLogEvent($logEvent);
			return TRUE;
		}

		$logEvent['eventResult'] = 3;
		$this->app()->addLogEvent($logEvent);

		return FALSE;
	}

	public function geoCodeLocHash ($recData)
	{
		return md5($recData['street'].' '.$recData['city'].' '.$recData['zipcode'].' '.$recData['country']);
	}

	function loadAddresses($table, $ndx, $inlineMode = FALSE)
	{
		$multipleRecs = is_array($ndx);

		$q[] = 'SELECT * FROM [e10_persons_address]';
		array_push($q, ' WHERE [tableid] = %s', $table->tableId ());

		if ($multipleRecs)
			array_push($q, ' AND recid IN %in', $ndx);
		else
			array_push($q, ' AND recid = %i', $ndx);

		array_push($q, ' ORDER BY ndx');

		$list = [];
		$addrTypes = $this->app()->cfgItem('e10.persons.addressTypes');

		$rows = $this->app()->db()->query ($q);
		forEach ($rows as $r)
		{
			$a = [];

			$txt = '';
			if ($r['street'] !== '')
				$txt .= $r['street'];
			if ($r['city'] !== '')
			{
				if ($txt !== '')
					$txt .= ', ';
				$txt .= $r['city'];
			}
			if ($r['zipcode'] !== '')
			{
				if ($txt !== '')
					$txt .= ', ';
				$txt .= $r['zipcode'];
			}

			if ($inlineMode)
				$a = ['text' => $txt, 'class' => 'block', 'icon' => 'system/iconMapMarker'];
			else
			{
				$a = $r->toArray();
				$a['text'] = $txt;
				$a['icon'] = $addrTypes[$r['type']]['icon'] ?? 'system/iconWarning';
				$a['typeTitle'] = $addrTypes[$r['type']]['name'] ?? '!!!';
			}

			if ($multipleRecs)
				$list[$r['recid']][] = $a;
			else
				$list[] = $a;
		}

		return $list;
	}

	public function addressText($r, $inlineMode = FALSE)
	{
		$txt = '';
		if ($r['street'] !== '')
			$txt .= $r['street'];
		if ($r['city'] !== '')
		{
			if ($txt !== '')
				$txt .= ', ';
			$txt .= $r['city'];
		}
		if ($r['zipcode'] !== '')
		{
			if ($txt !== '')
				$txt .= ', ';
			$txt .= $r['zipcode'];
		}

		if ($inlineMode)
		{
			$a = ['text' => $txt, 'class' => 'block', 'icon' => 'system/iconMapMarker'];
			return $a;
		}

		return $txt;
	}
}


/**
 * Class FormAddress
 * @package e10\persons
 */
class FormAddress extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('type');
			$this->addColumnInput ('specification');
			$this->addColumnInput ('street');
			$this->addColumnInput ('city');
			$this->addColumnInput ('zipcode');
			$this->addColumnInput ('worldCountry');

			$this->addList ('clsf', '', TableForm::loAddToFormLayout);
		$this->closeForm ();
	}
}

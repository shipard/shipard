<?php

namespace services\persons\dataView;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Str;
use \Shipard\Utils\Json;
use \lib\dataView\DataView;


/**
 * @class DataViewPersons
 */
class DataViewPersons extends DataView
{
	var $maxCount = 10;
	var $urlPrefix = '';
	var $today;

	CONST vtSearch = 0, vtSearchResults = 1, vtPerson = 2, vtError = 3;
	var $viewType = self::vtError;

	var $enabledCountries = ['cz', 'sk'];
	var $enabledShowAs = ['html', 'json'];

	protected function init()
	{
		parent::init();

		$this->requestParams['showAs'] = strval($this->app()->requestPath(3));
		if ($this->requestParams['showAs'] === '')
			$this->requestParams['showAs'] = 'html';

		if (!in_array($this->requestParams['showAs'], $this->enabledShowAs))
		{
			$this->data['errors'][] = ['msg' => 'Nepodporovaný formát `'.$this->requestParams['showAs'].'`'];
			$this->viewType = self::vtError;
			$this->requestParams['showAs'] = 'html';
			return;
		}

		$this->requestParams['country'] = $this->app()->requestPath(1);
		$this->requestParams['personId'] = $this->app()->requestPath(2);
		$this->data['queryText'] = $this->app()->testGetParam('q');

		if ($this->requestParams['country'] !== '' && !in_array($this->requestParams['country'], $this->enabledCountries))
		{
			$this->data['errors'][] = ['msg' => 'Chybná země `'.$this->requestParams['country'].'`'];
			$this->viewType = self::vtError;
			return;
		}

		
		if ($this->requestParams['country'] === '' && $this->requestParams['personId'] === '' && $this->data['queryText'] === '')
			$this->viewType = self::vtSearch;
		elseif ($this->requestParams['country'] === '' && $this->requestParams['personId'] === '' && $this->data['queryText'] !== '')
			$this->viewType = self::vtSearchResults;
		elseif ($this->requestParams['country'] !== '' && $this->requestParams['personId'] !== '')
			$this->viewType = self::vtPerson;

		$this->today = utils::today('Y-m-d');
	}

	protected function loadData()
	{
		if ($this->viewType === self::vtSearchResults)
			$this->loadData_searchResults();
		elseif ($this->viewType === self::vtPerson)
			$this->loadData_person();
	}

	protected function loadData_searchResults()
	{
		$fts = $this->data['queryText'];

		$q = [];
		array_push ($q, ' SELECT * FROM (');
		array_push ($q, ' SELECT 2 AS selectPart, persons.* ');
		array_push ($q, ' FROM [services_persons_persons] AS persons');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			$doExtraLIKE = 1;

			$words = preg_split('/[\s-]+/', $fts);
			$cntUsedWords = 0;
			$fullTextQuery = '';
			foreach ($words as $w)
			{
				if (Str::strlen($w) < 3)
					continue;
				if (substr_count($w, '.') > 1)
					continue;
				if ($w[0] === '+')	
					continue;
				if ($fullTextQuery !== '')
					$fullTextQuery .= ' ';
				$fullTextQuery .= '+'.$w;
				$cntUsedWords++;
			}

			if ($fullTextQuery !== '')
			{
//				array_push ($q, ' AND (1 ');
				array_push ($q, ' AND MATCH([fullName]) AGAINST (%s IN BOOLEAN MODE)', $fullTextQuery);
				if (count($words) === $cntUsedWords)
					$doExtraLIKE = 0;
//				array_push ($q, ')');
			}	
			else
			{
				//if (Str::strlen($fts) > 2)
					array_push($q, ' AND persons.[fullName] LIKE %s', $fts . '%');
					$doExtraLIKE = 0;
			}
			
			$ascii = TRUE;
			if(preg_match('/[^\x20-\x7f]/', $fts))
				$ascii = FALSE;
			
			if ($ascii)
			{
				array_push ($q, 'UNION DISTINCT SELECT 1 AS selectPart, persons.* ');
				array_push ($q, ' FROM [services_persons_persons] AS persons');
				array_push ($q, " WHERE EXISTS (SELECT ndx FROM services_persons_ids WHERE persons.ndx = services_persons_ids.person AND [id] = %s)", $fts);
			}

			$spaceParts = explode(' ', $fts);
			if (count($spaceParts) < 5 && $doExtraLIKE)
			{
				array_push ($q, 'UNION DISTINCT SELECT 1 AS selectPart, persons.* ');
				array_push ($q, ' FROM [services_persons_persons] AS persons');
				array_push ($q, ' WHERE persons.[fullName] LIKE %s', $fts . '%');
			}
		}
		array_push ($q, ') AS ALL_PERSONS');

		if ($fts !== '')
			array_push ($q, ' ORDER BY selectPart, valid DESC, fullName');
		else
			array_push ($q, ' ORDER BY selectPart, fullName');	
		
		array_push ($q, ' LIMIT 20');

		$pks = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->data['search'][$r['ndx']] = $r->toArray();
			$this->data['search'][$r['ndx']]['validToH'] = Utils::datef($r['validTo'], '%d');
			$pks[] = $r['ndx'];
		}

		// -- address
		if (count($pks))
		{
			$rows = $this->db()->query('SELECT * FROM [services_persons_address] WHERE [person] IN %in', $pks);
			foreach ($rows as $r)
			{
				$address = $r->toArray();
				$address['addressText'] = \services\persons\libs\CoreObject::addressText($address);
				if ($address['type'] === 0)
					$this->data['search'][$r['person']]['primaryAddressText'] = $address['addressText'];
			}
		}

		if (isset($this->data['search']))
			$this->data['search'] = array_values($this->data['search']);
	}

	protected function loadData_person()
	{
		$personData = new \services\persons\libs\PersonData($this->app);
		$personData->loadById ($this->requestParams['country'], $this->requestParams['personId']);

		if ($personData->data)
		{
			$personData->prepareDataExport();
			$personData->prepareDataShow();
			$this->data['person'] = $personData->dataShow;
			$this->data['person']['json'] = Json::lint($personData->dataExport);
		}
		else
		{
			$this->viewType = self::vtError;
			$this->data['errors'][] = ['msg' => 'IČ `'.$this->requestParams['personId'].'` není platné...'];

			$data = array_merge(['status' => 0], ['errors' => array_values($this->data['errors'])]);
			$this->data['person']['json'] = Json::lint($data);
		}
	}

	protected function renderDataAs($showAs)
	{
		if ($showAs === 'html')
    	return $this->renderDataAsHtml();
		if ($showAs === 'json')
    	return $this->renderDataAsJson();

		return parent::renderDataAs($showAs);
	}

	protected function renderDataAsHtml()
	{
		$c = '';

		$this->template->data['dataView'] = $this->data;

		if ($this->viewType === self::vtSearch || $this->viewType === self::vtSearchResults)
			$c .= $this->template->renderSubTemplate('services.persons.dataview-persons-search');
		elseif ($this->viewType === self::vtPerson)
			$c .= $this->template->renderSubTemplate('services.persons.dataview-persons-person');
		elseif ($this->viewType === self::vtError)
			$c .= $this->template->renderSubTemplate('services.persons.dataview-persons-error');

		unset($this->template->data['dataView']);


		return $c;
	}

	protected function renderDataAsJson()
	{
		if (isset($this->data['person']['json']))
			$this->template->data['forceCode'] = $this->data['person']['json'];
		else
		{
			$data = array_merge(['status' => 0], ['errors' => array_values($this->data['errors'])]);
			$this->template->data['forceCode'] = Json::lint($data);
		}	
		$this->template->data['forceMimeType'] = 'application/json';
	}
}

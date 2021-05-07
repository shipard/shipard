<?php

namespace e10pro\property;

use E10\Application, E10\TableForm, E10\Wizard, E10\utils;


/**
 * Class DocumentCardProperty
 * @package e10pro\property
 */
class DocumentCardProperty extends \e10\DocumentCard
{
	var $showDeprecations = FALSE;

	public function createContentHeader ()
	{
		$title = ['icon' => $this->table->tableIcon ($this->recData), 'text' => $this->recData ['fullName']];
		$this->addContent('title', ['type' => 'line', 'line' => $title]);
		$this->addContent('subTitle', ['type' => 'line', 'line' => '#'.$this->recData ['propertyId']]);
	}

	public function accessory ()
	{
		$accessory = [];

		$q[] = 'SELECT acc.*, types.fullName AS propertyType FROM [e10pro_property_propertyAccessory] AS links';
		array_push($q, ' LEFT JOIN e10pro_property_property AS acc ON links.linkedProperty = acc.ndx');
		array_push($q, ' LEFT JOIN e10pro_property_types AS types ON acc.propertyType = types.ndx');
		array_push($q, ' WHERE [property] = %i', $this->recData['ndx']);

		$rows = $this->app()->db()->query ($q);
		foreach ($rows as $r)
		{
			$i = [
				'text' => $r['fullName'], 'prefix' => $r['propertyId'], 'class' => 'block',
				'docAction' => 'edit', 'table' => 'e10pro.property.property', 'pk' => $r['ndx'],
			];
			if ($r['propertyType'])
				$i['suffix'] = $r['propertyType'];
			$accessory[] = $i;
		}

		if (count($accessory) === 0)
			return FALSE;

		return $accessory;
	}

	public function accessoryFor ()
	{
		$accessory = [];

		$q[] = 'SELECT property.*, types.fullName AS propertyType FROM [e10pro_property_propertyAccessory] AS links';
		array_push($q, ' LEFT JOIN e10pro_property_property AS property ON links.property = property.ndx');
		array_push($q, ' LEFT JOIN e10pro_property_types AS types ON property.propertyType = types.ndx');
		array_push($q, ' WHERE [linkedProperty] = %i', $this->recData['ndx']);

		$rows = $this->app()->db()->query ($q);
		foreach ($rows as $r)
		{
			$i = [
				'text' => $r['fullName'], 'prefix' => $r['propertyId'], 'class' => 'block',
				'docAction' => 'edit', 'table' => 'e10pro.property.property', 'pk' => $r['ndx'],
			];
			if ($r['propertyType'])
				$i['suffix'] = $r['propertyType'];
			$accessory[] = $i;
		}

		if (count($accessory) === 0)
			return FALSE;

		return $accessory;
	}

	public function createContent_Card ()
	{
		$row = 0;
		$t[$row] = ['t1' => 'Inventární číslo', 'v1' => $this->recData['propertyId'], 't2' => '', 'v2' => ''];
		//$t[$row]['_options']['cellClasses']['t1'] = 'width20';
		//$t[$row]['_options']['cellClasses']['v1'] = 'width30';
		//$t[$row]['_options']['cellClasses']['t2'] = 'width30';
		//$t[$row++]['_options']['cellClasses']['v2'] = 'width20';

		$q = 'SELECT [person], [centre], [place] FROM [e10pro_property_states] WHERE [property] = %i';
		$stateRec = $this->app()->db()->query ($q, $this->recData['ndx'])->fetch();
		if (isset ($stateRec))
		{
			if ($stateRec['person'] != 0)
			{
				$tablePersons = new \E10\Persons\TablePersons ($this->app());
				$person = ['ndx' => $stateRec['person']];
				$t[$row++] = ['t1' => 'Osoba', 'v1' => $tablePersons->loadItem ($person)['fullName'], 't2' => '', 'v2' => ''];
			}
			if ($stateRec['centre'] != 0)
			{
				$tableCentres = new \E10Doc\Base\TableCentres($this->app());
				$centre = ['ndx' => $stateRec['centre']];
				$centreRec = $tableCentres->loadItem ($centre);
				$t[$row++] = ['t1' => 'Středisko', 'v1' => $centreRec['shortName'], 't2' => '', 'v2' => ''];
			}
			if ($stateRec['place'] != 0)
			{
				/** @var \e10\base\TablePlaces $tablePlaces */
				$tablePlaces = $this->app()->table('e10.base.places');
				$place = ['ndx' => $stateRec['place']];
				$t[$row++] = ['t1' => 'Místo', 'v1' => $tablePlaces->loadItem ($place)['fullName'], 't2' => '', 'v2' => ''];
			}
		}
		$row = 0;
		if (isset ($this->recData['dateStart']))
		{
			$t[$row]['t2'] = 'Datum pořízení';
			$t[$row++]['v2'] = utils::datef($this->recData['dateStart']);
		}
		$q = 'SELECT [dateAccounting] FROM [e10pro_property_deps] WHERE [property] = %i AND [docState] = 4000 AND [rowType] = 1 ORDER BY [dateAccounting] LIMIT 1';
		$dtsRec = $this->app()->db()->query ($q, $this->recData['ndx'])->fetch();
		if (isset ($dtsRec['dateAccounting']))
		{
			$t[$row]['t2'] = 'Datum zařazení';
			$t[$row++]['v2'] = utils::datef($dtsRec['dateAccounting']);
		}
		if ($this->recData['priceIn'])
		{
			$t[$row]['t2'] = 'Pořizovací cena';
			$t[$row++]['v2'] = utils::nf($this->recData['priceIn'], 2);
		}
		if (isset ($this->recData['dateEnd']))
		{
			$t[$row]['t2'] = 'Datum vyřazení';
			$t[$row++]['v2'] = utils::datef($this->recData['dateEnd']);
		}

		$row = count($t);
		$accessory = $this->accessory();
		if ($accessory !== FALSE)
		{
			$t[$row] = ['t1' => 'Příslušenství', 'v1' => $accessory];
			$t[$row]['_options']['colSpan']['v1'] = 3;
			$row++;
		}
		$accessoryFor = $this->accessoryFor();
		if ($accessoryFor !== FALSE)
		{
			$t[$row] = ['t1' => 'Patří k', 'v1' => $accessoryFor];
			$t[$row]['_options']['colSpan']['v1'] = 3;
		}

		$h = ['t1' => '', 'v1' => '', 't2' => '', 'v2' => ''];

		$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => $h, 'table' => $t,
				'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']]);
	}

	public function createContent_Note ()
	{
		if ($this->recData['note'] != '')
			$this->addContent('body', ['type' => 'text', 'subtype' => 'auto', 'text' => $this->recData ['note']]);
	}

	public function createContent_Places ()
	{
		if ($this->recData['propertyKind'] !== 1)
			return;

		$placeStates = [];

		// -- TO
		$q = 'SELECT placeTo as place, places.fullName as placeName, SUM(quantityTo) as q FROM e10pro_property_operations as operations '.
				'LEFT JOIN e10_base_places as places ON operations.placeTo = places.ndx '.
				'WHERE property = %i AND operations.docState = 4000 GROUP BY placeTo';
		$placesRows = $this->db()->query ($q, $this->recData['ndx']);
		$totalQ = 0;
		foreach ($placesRows as $r)
		{
			if (!$r['place'])
				continue;
			$placeStates [$r['place']] = ['name' => $r['placeName'], 'q' => $r['q']];
			$totalQ += $r['q'];
		}

		// -- FROM
		$q = 'SELECT placeFrom as place, places.fullName as placeName, SUM(quantityFrom) as q FROM e10pro_property_operations as operations '.
				'LEFT JOIN e10_base_places as places ON operations.placeFrom = places.ndx '.
				'WHERE property = %i AND operations.docState = 4000 GROUP BY placeFrom';
		$placesRows = $this->db()->query ($q, $this->recData['ndx']);
		foreach ($placesRows as $r)
		{
			if (!$r['place'])
				continue;
			if (isset ($placeStates [$r['place']]))
				$placeStates [$r['place']]['q'] += $r['q'];
			else
				$placeStates [$r['place']] = ['name' => $r['placeName'], 'q' => $r['q']];
			$totalQ += $r['q'];
		}

		if (count($placeStates) > 1)
		{
			$placeStates[] = ['name' => 'Celkem', 'q' => $totalQ, '_options' => ['class' => 'sum']];
		}

		if (count($placeStates))
		{
			$h = ['name' => 'Místo', 'q' => ' Množství'];
			$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table',
					'type' => 'table', 'title' => ['icon' => 'icon-th-list', 'text' => 'Množství na jednotlivých místech'],
					'header' => $h, 'table' => $placeStates, 'params' => ['precision' => 0]]);
		}
	}

	public function createContent_DepsInfo ()
	{
		if (!$this->table->useDepreciations($this->recData, TRUE))
			return;

		if ($this->recData['depreciationType'] === 'AN')
		{
			/*
			$this->addContent ('body',
					[
							'pane' => 'e10-pane e10-pane-table', 'type' => 'line',
							'line' => [
									['icon' => 'icon-sort-amount-desc', 'text' => 'Odpisy', 'class' => 'h1'],
									['text' => 'Tento majetek se neodepisuje', 'class' => 'break info']
							]
					]);
			*/
			return;
		}

		$de = new \e10pro\property\DepreciationsEngine ($this->app());
		$de->init();
		$de->setProperty($this->recData['ndx']);
		$de->createDepsPlan();
		$tt = $de->depsOverviewContent();
		$de->createInfo();

		$tt[0]['title'] = 'Přehled odpisů';

		$this->addContent ('body',
				[
						'pane' => 'e10-pane e10-pane-table',
						'title' => ['icon' => 'icon-sort-amount-desc', 'text' => 'Odpisy'],
						'type' => 'table',
						'tables' => array_merge([['header' => $de->info['depsInfoHeader'], 'table' => $de->info['depsInfoTable']]], $tt)
				]);

		$errors = $de->createErrorsContent();
		if ($errors)
			$this->addContent ('body', $errors);
	}


	public function createContentBody ()
	{
		$this->createContent_Card();
		$this->createContent_Note();
		$this->createContent_Places();

		if ($this->showDeprecations)
		{
			$this->createContent_DepsInfo();
		}

		$this->addContent ('body', \E10\Base\getPropertiesDetail ($this->table, $this->recData));
		$this->addContentAttachments ($this->recData ['ndx']);
	}

	public function createContent ()
	{
		$this->createContentHeader ();
		$this->createContentBody ();
	}
}

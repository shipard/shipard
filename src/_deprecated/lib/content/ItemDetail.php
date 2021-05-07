<?php

namespace lib\content;
use E10\E10Object;
use e10\utility;


/**
 * Class ItemDetail
 * @package lib\content
 */
class ItemDetail extends E10Object
{
	var $title;
	/** @var  \e10\DbTable */
	var $table;
	var $recData;
	var $recNdx;

	var $contentPart;


	function load($table, $recData, $title = '')
	{
		$this->table = $table;
		$this->recData = $recData;
		$this->title = $title;

		$this->recNdx = $recData['ndx'];

		$this->contentPart = ['type' => 'properties', 'title' => $this->title, 'pane' => 'e10-pane e10-pane-table'];

		$this->loadProperties();
		$this->loadAddresses();
		$this->loadNomenclature();
	}

	protected function loadNomenclature ()
	{
		$this->contentPart['header'] = ['title' => 'Vlastnost', 'value' => 'Hodnota'];

		$q[] = 'SELECT nomenc.*, ';
		array_push($q, ' nomencTypes.shortName AS typeShortName, ');
		array_push($q, ' nomencItems.shortName AS itemShortName, nomencItems.itemId AS ItemitemId');
		array_push($q, ' FROM e10_base_nomenc AS nomenc');
		array_push($q, ' LEFT JOIN e10_base_nomencTypes as nomencTypes ON nomenc.nomencType = nomencTypes.ndx');
		array_push($q, ' LEFT JOIN e10_base_nomencItems as nomencItems ON nomenc.nomencItem = nomencItems.ndx');
		array_push($q, ' WHERE nomenc.tableId = %s', $this->table->tableId(), ' AND nomenc.recId = %i', $this->recNdx);
		array_push($q, ' ORDER BY nomencTypes.fullName, nomencItems.[order]');

		$t = [];

		$rows = $this->db()->query($q);

		forEach ($rows as $r)
		{
			$item = [
					'title' => $r['typeShortName'],
					'value' => $r['itemShortName']
			];

			$t[] = $item;
		}

		$this->contentPart['params'][] = ['title' => 'Zařazení', 'rows' => $t];
	}

	function loadAddresses()
	{
		$q[] = 'SELECT * FROM [e10_persons_address]';
		array_push($q, ' WHERE [tableid] = %s', $this->table->tableId (),' AND recid = %i', $this->recNdx);
		array_push($q, ' ORDER BY ndx');

		$t = [];
		$addrTypes = $this->app->cfgItem('e10.persons.addressTypes');

		$rows = $this->app->db()->query ($q);
		forEach ($rows as $r)
		{
			$a = [];

			if ($r['street'] !== '')
				$a[] = ['text' => $r['street'], 'class' => 'block'];
			if ($r['city'] !== '')
			{

				$a[] = ['text' => $r['zipcode'].' '.$r['city'], 'class' => 'block'];
			}

			$item = [
					'title' => $r['type'] ? $addrTypes[$r['type']]['name']:'Adresa',
					'value' => $a
			];

			$t[] = $item;
		}

		if (count($t))
			$this->contentPart['params'][] = ['title' => 'Adresa', 'rows' => $t];
	}

	function loadProperties ()
	{
		$allProperties = $this->app->cfgItem ('e10.base.properties', []);
		$allPropertiesGroups = $this->app->cfgItem ('e10.base.propertiesGroups', []);
		$properties = $this->getPropertiesTable ($this->table->tableId(), $this->recNdx);

		forEach ($properties as $groupId => $groupContent)
		{
			$newGroup = array ('title' => $allPropertiesGroups[$groupId]['name'], 'rows' => []);

			forEach ($groupContent as $propertyId => $propertyValues)
			{
				$p = $allProperties [$propertyId];
				if ($p ['type'] == 'memo')
					continue;

				$propvals = array ();
				forEach ($propertyValues as $pv)
				{
					$vv = $pv ['value'];
					if (isset ($pv ['note']) && $pv ['note'] != '')
						$vv .= ' ('.$pv ['note'].')';
					$propvals[] = $vv;
				}

				$newGroup ['rows'][] = array ('title' => $p ['name'], 'value' => implode(', ', $propvals));
			}

			if (count($newGroup ['rows']))
				$this->contentPart['params'][] = $newGroup;
		}

		forEach ($properties as $groupId => $groupContent)
		{
			forEach ($groupContent as $propertyId => $propertyValues)
			{
				$p = $allProperties [$propertyId];
				if ($p ['type'] != 'memo')
					continue;

				$propvals = array ();
				forEach ($propertyValues as $pv)
					$propvals[] = $pv ['value'];

				$this->contentPart['texts'][] = ['title' => $p ['name'], 'text' => implode('<hr/>', $propvals)];
			}
		}
	}

	function getPropertiesTable ($toTableId, $toRecId)
	{
		$multiple = FALSE;
		$texy = new \E10\Web\E10Texy ($this->app);

		if (is_array($toRecId))
		{
			$multiple = TRUE;
			$recs = implode (', ', $toRecId);
			$sql = "SELECT * FROM [e10_base_properties] where [tableid] = %s AND [recid] IN ($recs) ORDER BY ndx";
		}
		else
		{
			$recId = intval($toRecId);
			$sql = "SELECT * FROM [e10_base_properties] where [tableid] = %s AND [recid] = $recId ORDER BY ndx";
		}

		$allProperties = $this->app->cfgItem ('e10.base.properties', array());
		$properties = array ();

		// -- load from table
		$query = $this->app->db->query ($sql, $toTableId);
		foreach ($query as $row)
		{
			$loaded = false;
			$p = $allProperties [$row['property']];
			if (isset ($p ['type']))
			{
				if ($p ['type'] === 'memo')
				{
					$texy->setOwner ($row);
					$txt = $texy->process ($row ['valueMemo']);
					$oneProp = ['ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'], 'value' => $txt, 'type' => 'memo', 'name' => $p ['name']];
					$loaded = true;
				}
				else if ($p ['type'] === 'date')
				{
					$oneProp = ['ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'], 'value' => $row ['valueDate'], 'type' => 'memo', 'name' => $p ['name']];
					$loaded = true;
				}
				elseif ($p ['type'] === 'text')
				{
					$oneProp = ['ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'], 'value' => $row ['valueString'], 'type' => 'text', 'name' => $p ['name']];
					$loaded = true;
				}
				elseif ($p ['type'] === 'enum')
				{
					$oneProp = ['ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'], 'value' => $p ['enum'][$row ['valueString']]['fullName'],
							'type' => 'enum', 'name' => $p ['name']];
					$loaded = true;
				}

				if ($loaded && $row ['note'] !== '')
					$oneProp ['note'] = $row ['note'];
			}
			if (!$loaded)
				$oneProp = ['ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'], 'value' => $p [$row ['valueString']], 'type' => 'string', 'name' => $p ['name']];

			if ($multiple)
				$properties [$row ['recid']][$row['group']][$row['property']][] = $oneProp;
			else
				$properties [$row['group']][$row['property']][] = $oneProp;
		}

		return $properties;
	}

}

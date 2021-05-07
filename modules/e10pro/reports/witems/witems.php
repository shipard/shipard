<?php

namespace E10Pro\Reports\Witems;


/**
 * reportDuplicities
 *
 */

class reportDuplicities extends \e10doc\core\libs\reports\GlobalReport
{
	var $tableItems;

	function init ()
	{
		$this->addParam('switch', 'deleted', array ('title' => 'Včetně smazaných', 'switch' => array('0' => 'Ne', '1' => 'Ano')));

		parent::init();
		$this->tableItems = $this->app->table ('e10.witems.items');

		$this->setInfo('title', 'Duplicitní položky');
		$this->setInfo('icon', 'icon-code-fork');
		$this->setInfo('param', 'Včetně smazaných', $this->reportParams ['deleted']['activeTitle']);
	}

	function createContent ()
	{
		// -- properties
		$allProperties = $this->app->cfgItem ('e10.base.properties', array());
		$q[] = 'SELECT [property], [group], [tableid], [valueString], COUNT(*) as cnt from e10_base_properties props';
		array_push ($q, ' LEFT JOIN e10_witems_items AS items ON props.recid = items.ndx AND props.tableid = %s', 'e10.witems.items');
		array_push ($q, ' WHERE [group] = %s', 'itemids', ' AND valueString != %s', '');

		if ($this->reportParams ['deleted']['value'] == 0)
			array_push ($q, ' AND items.[docState] != %i', 9800);

		array_push ($q, ' GROUP BY property, [group], tableid, valueString HAVING cnt > 1');

		$rows = $this->app->db()->query($q);

		$data = array ();

		forEach ($rows as $r)
		{
			$p = $allProperties [$r['property']];

			$newItem = array ('wn' => $p['name'].' '.$r['valueString'],
												'_options' => array ('class' => 'subheader separator', 'colSpan' => array ('wn' => 3)));
			$data[] = $newItem;

			$qi [] = 'SELECT items.*, types.fullName as itemTypeName, ';
			array_push ($qi, ' (SELECT COUNT(*) FROM e10doc_core_rows WHERE item = items.ndx) as cntRows');
			array_push ($qi, ' FROM [e10_witems_items] items');
			array_push ($qi, ' LEFT JOIN e10_witems_itemtypes AS types ON items.itemType = types.ndx');
			array_push ($qi, ' WHERE EXISTS (SELECT ndx FROM e10_base_properties WHERE items.ndx = e10_base_properties.recid AND valueString = %s AND tableid = %s AND property = %s AND [group] = %s)',
												$r['valueString'], 'e10.witems.items', $r['property'], $r['group']);
			$items = $this->app->db()->query($qi);

			$thisBlock = array();
			$itemsPks = array ();
			foreach ($items as $item)
			{
				if ($this->reportParams ['deleted']['value'] == 0 && $item['docState'] === 9800)
					continue;

				$itemNdx = $item['ndx'];

				$docStates = $this->tableItems->documentStates ($item);
				$docStateClass = $this->tableItems->getDocumentStateInfo ($docStates, $item, 'styleClass');

				$itm = array (
											'wn' => array ('text' => $item['id'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $itemNdx),
											'name' => $item['fullName'], 'type' => $item['itemTypeName'], 'cntRows' => $item['cntRows'],
											'_options' => array ('cellClasses' => array('wn' => $docStateClass))
				);

				$itemsPks[] = $itemNdx;
				$thisBlock[] = $itm;
			}

			foreach ($thisBlock as $oneItem)
			{
				$oneItem ['merge'] = array ('type' => 'action', 'action' => 'addwizard', 'text' => 'Sloučit', 'icon' => 'icon-code-fork', 'class' => 'btn-xs',
																		'data-table' => 'e10.witems.items', 'data-pk' => $oneItem['wn']['pk'], 'data-class' => 'e10.witems.MergeItemsWizard',
																		'data-addparams' => 'mergedItems='.implode(',', $itemsPks));
				$data[] = $oneItem;
			}

			unset($thisBlock);
			unset($itemsPks);
			unset($qi);
		}
		unset($q);

		// -- names
		$q[] = 'SELECT fullName, COUNT(fullName) as cnt from [e10_witems_items]';
		if ($this->reportParams ['deleted']['value'] == 0)
			array_push ($q, ' WHERE [docState] != %i', 9800);
		array_push ($q, ' GROUP BY fullName HAVING cnt > 1');

		$rows = $this->app->db()->query($q);
		forEach ($rows as $r)
		{
			$newItem = array ('wn' => 'Název: '.$r['fullName'],
												'_options' => array ('class' => 'subheader separator', 'colSpan' => array ('wn' => 3)));
			$data[] = $newItem;

			$qi [] = 'SELECT items.*, types.fullName as itemTypeName, ';
			array_push ($qi, ' (SELECT COUNT(*) FROM e10doc_core_rows WHERE item = items.ndx) as cntRows');
			array_push ($qi, ' FROM [e10_witems_items] items');
			array_push ($qi, ' LEFT JOIN e10_witems_itemtypes AS types ON items.itemType = types.ndx');
			array_push ($qi, ' WHERE items.fullName = %s', $r['fullName']);
			array_push ($qi, ' ORDER BY id');
			$items = $this->app->db()->query($qi);

			$thisBlock = array();
			$itemsPks = array ();
			foreach ($items as $item)
			{
				if ($this->reportParams ['deleted']['value'] == 0 && $item['docState'] === 9800)
					continue;
				$itemNdx = $item['ndx'];

				$docStates = $this->tableItems->documentStates ($item);
				$docStateClass = $this->tableItems->getDocumentStateInfo ($docStates, $item, 'styleClass');

				$itm = array (
											'wn' => array ('text' => $item['id'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $itemNdx),
											'name' => $item['fullName'], 'type' => $item['itemTypeName'], 'cntRows' => $item['cntRows'],
											'_options' => array ('cellClasses' => array('wn' => $docStateClass))
				);
				$itemsPks[] = $itemNdx;
				$thisBlock[] = $itm;
			}

			foreach ($thisBlock as $oneItem)
			{
				$oneItem ['merge'] = array ('type' => 'action', 'action' => 'addwizard', 'text' => 'Sloučit', 'icon' => 'icon-code-fork', 'class' => 'btn-xs',
																		'data-table' => 'e10.witems.items', 'data-pk' => $oneItem['wn']['pk'], 'data-class' => 'e10.witems.MergeItemsWizard',
																		'data-addparams' => 'mergedItems='.implode(',', $itemsPks));
				$data[] = $oneItem;
			}

			unset($thisBlock);
			unset($itemsPks);
			unset($qi);
		}

		$title = 'Duplicitní položky';

		$h = array ('wn' => 'Položka', 'name' => 'Název', 'type' => 'Typ', 'cntRows' => ' Pohyby', 'merge' => 'Akce');
		$this->addContent (array ('type' => 'table', 'title' => $title, 'header' => $h, 'table' => $data));
	}
}


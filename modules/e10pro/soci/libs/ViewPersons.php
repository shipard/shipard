<?php

namespace e10pro\soci\libs;
use \Shipard\Viewer\TableViewPanel;

/**
 * class ViewPersons
 */
class ViewPersons extends \e10\persons\ViewPersons
{
  var $showWorkOrders = 1;
  var $workOrders = [];
	var $entryKinds = NULL;

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		parent::selectRows2();

		if ($this->showWorkOrders)
		{
			$tableWorkOrders = $this->app()->table ('e10mnf.core.workOrders');

			$q[] = 'SELECT [rows].person, [rows].workOrder, wo.docNumber, wo.title AS woTitle,';
			array_push($q, ' wo.docState as woDocState, wo.docStateMain as woDocStateMain, wo.intTitle as woIntTitle, wo.refId1 as woRefId1');
			array_push($q, ' FROM [e10mnf_core_workOrdersPersons] AS [rows]');
			array_push($q, ' LEFT JOIN [e10mnf_core_workOrders] AS wo ON [rows].workOrder = wo.ndx');
			array_push($q, ' WHERE [rows].person IN %in', $this->pks);

			$rows = $this->db()->query($q);
			foreach ($rows as $r)
			{
				$docNdx = $r['person'];
				$woNumber = $r['docNumber'];
				if (isset($this->workOrders[$docNdx][$woNumber]))
					$this->workOrders[$docNdx][$woNumber]['amount'] += $r['priceAll'];
				else
				{
					$this->workOrders[$docNdx][$woNumber]['amount'] = $r['priceAll'];
					$t = '';
					if ($r['woTitle'] && $r['woTitle'] !== '')
					{
						if ($t !== '')
							$t .= "\n ✎ ";
						$t .= $r['woTitle'];
					}
					if ($r['woIntTitle'])
					{
						if ($t !== '')
							$t .= "\n ✎ ";
						$t .= $r['woIntTitle'];
					}
					if ($t !== '')
						$this->workOrders[$docNdx][$woNumber]['title'] = $t;

					if ($r['woRefId1'] !== '')
						$this->workOrders[$docNdx][$woNumber]['refId1'] = $r['woRefId1'];

					$woItem = ['docState' => $r['woDocState'], 'docStateMain' => $r['woDocStateMain']];
					$woDocState = $tableWorkOrders->getDocumentState ($woItem);
					$woDocStateClass = $tableWorkOrders->getDocumentStateInfo ($woDocState['states'], $woItem, 'styleClass');
					$this->workOrders[$docNdx][$woNumber]['docStateClass'] = $woDocStateClass;
				}
			}
		}
	}

  function decorateRow (&$item)
	{
		if (!isset($item ['pk']))
			return;

    parent::decorateRow($item);

		if (isset ($this->workOrders[$item ['pk']]))
		{
			$inv = [];
			$totalCnt = count($this->workOrders[$item ['pk']]);
			$plus = NULL;
			$plusCnt = 0;
			$max = 2;
			$cnt = 0;
			foreach ($this->workOrders[$item ['pk']] as $docNumber => $wo)
			{
				$cnt++;
				if ($cnt <= $max || (!$plusCnt && ($totalCnt - $cnt) == 0))
				{
					$docNumber = ['text' => $wo['title'], 'title' => $docNumber, 'class' => 'tag tag-small '.$wo['docStateClass'], 'icon' => 'tables/e10mnf.core.workOrders'];
					if (isset($wo['refId1']))
						$docNumber['suffix'] = $wo['refId1'];
					$inv[] = $docNumber;
				}
				else
				{
					if ($plus === NULL)
						$plus = ['class' => 'tag tag-small tag-info', 'icon' => 'tables/e10mnf.core.workOrders', 'amount' => 0.0];
					$plus['amount'] += $wo['amount'];
					$plusCnt++;
				}
			}
			if ($plus)
			{
				$plus['text'] = '+ '.$plusCnt.' dalších';
				$inv[] = $plus;
			}
			$item['t2'] = array_merge($item['t2'], $inv);
		}
  }

	protected function createPanelContentQry1 (TableViewPanel $panel, &$qry)
	{
		$this->entryKinds = $this->table->app()->cfgItem ('e10pro.soci.entriesKinds', FALSE);

		$placesNdxs = [];
		$paramsWO = new \Shipard\UI\Core\Params ($this->app());
		$chbxWO = [];
		foreach ($this->entryKinds as $ekId => $ekCfg)
		{
			if (intval($ekCfg['workOrderKind'] ?? 0))
			{
				$woKind = $this->app()->cfgItem('e10mnf.workOrders.kinds.'.$ekCfg['workOrderKind'], NULL);
				if ($woKind)
				{
					$label = $woKind['fullName'];
					$woRows = $this->db()->query(
											'SELECT * FROM [e10mnf_core_workOrders] WHERE [docKind] = %i', $ekCfg['workOrderKind'],
											' AND docState = %i', 1200,
											' ORDER BY title, docNumber');
					foreach ($woRows as $wor)
					{
						$chbxWO[$wor['ndx']] = ['title' => $wor['title'], 'id' => $wor['ndx']];
						if ($label)
						{
							$chbxWO[$wor['ndx']]['label'] = $label;
							$label = NULL;
						}

						if (!in_array($wor['place'], $placesNdxs))
							$placesNdxs[] = $wor['place'];
					}
				}
			}
		}
		if (count($chbxWO))
		{
			$paramsWO->addParam ('checkboxes', 'query.wo', ['items' => $chbxWO]);
			$qry[] = ['id' => 'wo', 'style' => 'params', 'title' => 'Přihláška do', 'params' => $paramsWO];
		}

		if (count($placesNdxs))
		{
			$paramsPlaces = new \Shipard\UI\Core\Params ($this->app());
			$chbxPlaces = [];

			$placesRows = $this->db()->query('SELECT * FROM [e10_base_places] WHERE [ndx] IN %in', $placesNdxs);
			foreach ($placesRows as $pr)
			{
				$chbxPlaces[$pr['ndx']] = ['title' => $pr['shortName'], 'id' => $pr['ndx']];
			}

			$paramsPlaces->addParam ('checkboxes', 'query.places', ['items' => $chbxPlaces]);
			$qry[] = ['id' => 'places', 'style' => 'params', 'title' => 'Místo', 'params' => $paramsPlaces];
		}
	}

	public function qryPanel (array &$q)
	{
		parent::qryPanel($q);

		$qv = $this->queryValues();

		if (isset ($qv['wo']))
		{
			array_push ($q, ' AND EXISTS (',
			'SELECT ndx FROM e10mnf_core_workOrdersPersons WHERE persons.ndx = e10mnf_core_workOrdersPersons.person',
			' AND e10mnf_core_workOrdersPersons.[workOrder] IN %in', array_keys($qv['wo']), ')');
		}

		if (isset ($qv['places']))
		{
			array_push ($q, ' AND EXISTS (',
			'SELECT e10mnf_core_workOrdersPersons.ndx FROM e10mnf_core_workOrdersPersons ',
			' LEFT JOIN e10mnf_core_workOrders AS wos ON  e10mnf_core_workOrdersPersons.workOrder = wos.ndx',
			' WHERE persons.ndx = e10mnf_core_workOrdersPersons.person',
			' AND wos.[place] IN %in', array_keys($qv['places']), ')');
		}
	}
}


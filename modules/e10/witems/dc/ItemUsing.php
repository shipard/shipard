<?php

namespace e10\witems\dc;
use E10\utils;


/**
 * Class ItemUsing
 * @package e10\witems\dc
 */
class ItemUsing extends \e10\DocumentCard
{
	public function createContentBody()
	{
		$this->createContentBody_InSets();
		$this->createContentBody_InDocs();
		$this->createContentBody_InAccJournal();
	}

	public function createContentBody_InSets ()
	{
		$today = utils::today();

		$q = [];
		array_push ($q,'SELECT [setRows].*,');
		array_push ($q,' [ownerItems].fullName AS ownerFullName, [ownerItems].[id] AS ownerId,');
		array_push ($q,' [ownerItems].docState AS ownerItemDocState, [ownerItems].docStateMain AS ownerItemDocStateMain,');
		array_push ($q,' [ownerItems].validFrom AS ownerItemValidFrom, [ownerItems].validTo AS ownerItemValidTo,');
		array_push ($q,' [ownerTypes].fullName AS ownerTypeName, [ownerTypes].[type] AS ownerTypeType');
		array_push ($q,' FROM [e10_witems_itemsets] AS [setRows]');
		array_push ($q,' LEFT JOIN [e10_witems_items] AS [ownerItems] ON [setRows].[itemOwner] = [ownerItems].[ndx]');
		array_push ($q,' LEFT JOIN [e10_witems_itemtypes] AS [ownerTypes] ON ownerItems.itemType = [ownerTypes].ndx');
		array_push ($q,' WHERE [setRows].[item] = %i', $this->recData['ndx']);
		array_push($q, ' ORDER BY [ownerItems].docState, [ownerItems].fullName, [setRows].ndx');

		$t = [];
		$h = ['#' => '#', 'id' => 'Pol.', 'title' => 'Název', 'type' => 'Typ', 'quantity' => ' Množ.'];

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$itm = [
				'id' => ['text' => $r['ownerId'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk' => $r['itemOwner']],
				'title' => [['text' => $r['ownerFullName'], 'class' => 'block']],
				'type' => [['text' => $r['ownerTypeName'], 'class' => 'block']],
				'quantity' => $r['quantity'],
			];

			if (!utils::dateIsBlank($r['validTo']) )
			{
				$itm['title'][] = ['text' => 'Platné do '.utils::datef($r['validTo']), 'class' => 'label label-default'];
			}

			if (!utils::dateIsBlank($r['ownerItemValidTo']) && $r['ownerItemValidTo'] < $today)
			{
				if (utils::dateIsBlank($r['validTo']) || (!utils::dateIsBlank($r['validTo']) && $r['validTo'] > $r['ownerItemValidTo']))
				{
					$itm['title'][] = ['text' => 'Položka je neplatná k ' . utils::datef($r['ownerItemValidTo']), 'class' => 'e10-error block'];
				}
			}

			if (!utils::dateIsBlank($r['validFrom']))
			{
				$itm['title'][] = ['text' => 'Platné od ' . utils::datef($r['validFrom']), 'class' => 'label label-default'];
			}

			$itm['_options']['cellClasses']['id'] = $this->itemStateClass(['docState' => $r['ownerItemDocState'], 'docStateMain' => $r['ownerItemDocStateMain']]);

			$t[] = $itm;
		}

		if (!count($t))
			return;

		$title = [['text' => 'Tato položka je použitá v sadách', 'class' => 'h1']];

		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'paneTitle' => $title,
			'type' => 'table', 'table' => $t, 'header' => $h
		]);
	}

	public function createContentBody_InDocs()
	{
		if ($this->app()->model()->table ('e10doc.core.heads') === FALSE)
			return;

		$q = [];
		array_push ($q,'SELECT COUNT(*) AS cntRows, heads.docType, heads.fiscalYear, fy.fullName AS fyName');
		array_push ($q,' FROM e10doc_core_rows AS [rows]');
		array_push ($q,' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q,' LEFT JOIN [e10doc_base_fiscalyears] AS [fy] ON [heads].[fiscalYear] = [fy].[ndx]');
		array_push ($q,' WHERE [rows].[item] = %i', $this->recData['ndx']);
		array_push($q, ' GROUP BY heads.fiscalYear, heads.docType');

		$t = [];
		$h = ['dt' => 'Typ dokladu'];
		$docTypes = $this->app()->cfgItem ('e10.docs.types', []);


		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$rowId = '0dt-'.$r['docType'];
			$colId = '1fy-'.$r['fiscalYear'];
			if (!isset($t[$rowId]))
			{
				if (isset($docTypes[$r['docType']]))
				{
					$dt = $docTypes[$r['docType']];
					$t[$rowId] = ['dt' => ['text' => $dt['pluralName'], 'icon' => $dt['icon']]];
				}
				else
					$t[$rowId] = ['dt' => $r['docType']];
			}

			if (!isset($h[$colId]))
				$h[$colId] = ' '.$r['fyName'];

			$t[$rowId][$colId] = $r['cntRows'];
		}

		$title = [['text' => 'Počet řádků dokladů s touto položkou', 'class' => 'h1 block']];
		if (!count($t))
		{
			$title[] = ['text' => 'Položka není použita v žádném řádku dokladu', 'class' => 'e10-error'];
			$this->addContent ('body', [
				'pane' => 'e10-pane e10-pane-table',
				'type' => 'line', 'line' => $title
			]);
			return;
		}

		krsort($h);

		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'paneTitle' => $title,
			'type' => 'table', 'table' => $t, 'header' => $h
		]);
	}

	public function createContentBody_InAccJournal()
	{
		if ($this->app()->model()->table ('e10doc.debs.journal') === FALSE)
			return;

		if ($this->recData['itemKind'] != 2)
			return;

		$q = [];
		array_push ($q,'SELECT COUNT(*) AS cntRows, journal.docType, journal.fiscalYear, fy.fullName AS fyName');
		array_push ($q,' FROM e10doc_debs_journal AS [journal]');
		//array_push ($q,' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q,' LEFT JOIN [e10doc_base_fiscalyears] AS [fy] ON [journal].[fiscalYear] = [fy].[ndx]');
		array_push ($q,' WHERE [journal].[accountId] = %s', $this->recData['debsAccountId']);
		array_push($q, ' GROUP BY journal.fiscalYear, journal.docType');

		$t = [];
		$h = ['dt' => 'Typ dokladu'];
		$docTypes = $this->app()->cfgItem ('e10.docs.types', []);


		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$rowId = '0dt-'.$r['docType'];
			$colId = '1fy-'.$r['fiscalYear'];
			if (!isset($t[$rowId]))
			{
				if (isset($docTypes[$r['docType']]))
				{
					$dt = $docTypes[$r['docType']];
					$t[$rowId] = ['dt' => ['text' => $dt['pluralName'], 'icon' => $dt['icon']]];
				}
				else
					$t[$rowId] = ['dt' => $r['docType']];
			}

			if (!isset($h[$colId]))
				$h[$colId] = ' '.$r['fyName'];

			$t[$rowId][$colId] = $r['cntRows'];
		}

		$title = [['text' => 'Počet řádků účetního deníku s touto účtopoložkou', 'class' => 'h1 block']];
		if (!count($t))
		{
			$title[] = ['text' => 'Položka není použita v žádném řádku účetního deníku', 'class' => 'e10-error'];
			$this->addContent ('body', [
				'pane' => 'e10-pane e10-pane-table',
				'type' => 'line', 'line' => $title
			]);
			return;
		}

		krsort($h);

		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'paneTitle' => $title,
			'type' => 'table', 'table' => $t, 'header' => $h
		]);
	}

	public function createContent ()
	{
		$this->newMode = 1;

		$this->createContentBody ();
	}

	function itemStateClass($r)
	{
		$docStates = $this->table->documentStates($r);
		$docStateClass = $this->table->getDocumentStateInfo($docStates, $r, 'styleClass');
		return $docStateClass;
	}
}

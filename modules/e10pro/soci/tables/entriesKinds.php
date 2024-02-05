<?php

namespace e10pro\soci;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableEntriesKinds
 */
class TableEntriesKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.soci.entriesKinds', 'e10pro_soci_entriesKinds', 'Druhy přihlášek');
	}

	public function saveConfig ()
	{
		$docKinds = [];

		$rows = $this->app()->db->query ('SELECT * FROM [e10pro_soci_entriesKinds] WHERE [docState] != 9800 ORDER BY [order], [fullName], [ndx]');

		foreach ($rows as $r)
		{
			$dk = [
				'ndx' => $r ['ndx'],
				'fn' => $r ['fullName'], 'sn' => $r ['shortName'],
				'inputPerson' => $r ['inputPerson'],
				'useInbox' => intval($r ['useInbox']),
				'inboxSection' => intval($r ['inboxSection']),
				'workOrderKind' => intval($r ['workOrderKind']),
				'usePeriods' => intval($r ['usePeriods']),
				'useTestDrive' => intval($r ['useTestDrive']),
				'useSaleType' => intval($r ['useSaleType']),
				'usePaymentPeriod' => intval($r ['usePaymentPeriod']),
				'useItem' => intval($r ['useItem']),
				'itemType' => intval($r ['itemType']),
				'docNumberType' => intval($r ['docNumberType']),
			];

			$docKinds [strval($r['ndx'])] = $dk;
		}

		// save to file
		$cfg ['e10pro']['soci']['entriesKinds'] = $docKinds;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.soci.entriesKinds.json', utils::json_lint (json_encode ($cfg)));
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewEntriesKinds
 */
class ViewEntriesKinds extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10pro_soci_entriesKinds]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
					' [fullName] LIKE %s', '%'.$fts.'%',
					' OR [shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);


		$props = [];
		$props[] = ['text' => '#'.$item['ndx'], 'class' => 'pull-right e10-small e10-id'];
		$props[] = ['text' => $item['shortName'], 'class' => ''];

		if ($item['order'])
			$props[] = ['text' => utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'pull-right label label-default'];

		$listItem ['t2'] = $props;

		return $listItem;
	}
}


/**
 * class FormDocKind
 */
class FormEntryKind extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('inputPerson');
					$this->addColumnInput ('useInbox');
					$this->addColumnInput ('inboxSection');
					$this->addColumnInput ('workOrderKind');
					$this->addColumnInput ('usePeriods');
					$this->addColumnInput ('useTestDrive');
					$this->addColumnInput ('useSaleType');
					$this->addColumnInput ('usePaymentPeriod');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('useItem');
					if ($this->recData['useItem'])
						$this->addColumnInput ('itemType');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('order');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('docNumberType');
				$this->closeTab ();
		$this->closeTabs();
		$this->closeForm ();
	}
}

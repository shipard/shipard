<?php

namespace E10Doc\Base;

use \e10\utils, \e10\TableView, \Shipard\Form\TableForm, \e10\DbTable;


/**
 * Class TableDocNumbers
 * @package E10Doc\Base
 */
class TableDocNumbers extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.docnumbers', 'e10doc_base_docnumbers', 'Číselné řady dokladů');
	}

	public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		if ($columnId === 'docType')
		{
			if (!isset ($cfgItem['docNumbers']))
				return FALSE;
		}

		return parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}

	public function columnInfoEnumSrc ($columnId, $form)
	{
		if ($columnId === 'activitiesGroup')
		{
			if (!$form || !isset($form->recData['docType']))
				return NULL;

			$docType = $this->app()->cfgItem ('e10.docs.types.'.$form->recData['docType'], FALSE);
			if ($docType && isset ($docType['activitiesGroups']))
				return array_merge (['' => ''], $docType['activitiesGroups']);

			return NULL;
		}

		return parent::columnInfoEnumSrc ($columnId, $form);
	}

	public function saveConfig ()
	{
		$docNumbers = array ();
		$rows = $this->app()->db->query ('SELECT * from [e10doc_base_docnumbers] WHERE docState != 9800 ORDER BY [order], [tabName], [fullName], [docKeyId]');

		foreach ($rows as $r)
		{
			$dbc = [
					'ndx' => $r['ndx'], 'docKeyId' => $r ['docKeyId'], 'useDocKinds' => $r['useDocKinds'], 'docKind' => $r['docKind'],
					'activitiesGroup' => $r['activitiesGroup'], 'name' => $r ['fullName'], 'shortName' => $r ['shortName'],
					'tabName' => $r ['tabName'], 'emailSender' => $r['emailSender'],
					'usePersonsOffice' => $r['usePersonsOffice']
			];
			if ($r['firstNumberSet'])
			{
				$dbc['firstNumberSet'] = 1;
				$dbc['firstNumber'] = $r['firstNumber'];
				$dbc['firstNumberFiscalPeriod'] = $r['firstNumberFiscalPeriod'];
			}
			if ($r['emailSender'] == 2)
			{
				$dbc['emailFromAddress'] = $r['emailFromAddress'];
				$dbc['emailFromName'] = $r['emailFromName'];
			}

			$docNumbers [$r ['docType']][$r['ndx']] = $dbc;
		}

		// save to file
		$cfg ['e10']['docs']['dbCounters'] = $docNumbers;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.docNumbers.json', utils::json_lint (json_encode ($cfg)));
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = array ('class' => 'title', 'value' => $recData ['fullName']);

		return $hdr;
	}
} // class TableDocNumbers


/**
 * Class ViewDocNumbers
 * @package E10Doc\Base
 */
class ViewDocNumbers extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();

		$bt = [];
		$bt[] = ['id' => 'ALL', 'title' => 'Vše', 'active' => 1];

		$docTypes = $this->app->cfgItem ('e10.docs.types');

		forEach ($docTypes as $dtid => $dt)
		{
			if (!isset ($dt['docNumbers']))
				continue;
			$addParams = ['docType' => $dtid];
			$nbt = ['id' => $dtid, 'title' => $dt['shortcut'], 'active' => 0, 'addParams' => $addParams];
			$bt [] = $nbt;
		}
		$this->setBottomTabs ($bt);
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$bottomTabId = $this->bottomTabId ();

		$q [] = 'SELECT * FROM [e10doc_base_docnumbers]';
		array_push ($q, ' WHERE 1');

		if ($bottomTabId !== 'ALL')
			array_push ($q, ' AND [docType] = %s', $bottomTabId);

		if ($this->queryParam ('docType'))
			array_push ($q, ' AND [docType] = %s', $this->queryParam ('docType'));

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
					' [fullName] LIKE %s', '%'.$fts.'%', ' OR [shortName] LIKE %s', '%'.$fts.'%',
					' OR [docKeyId] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[tabName]', '[fullName]', '[docKeyId]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$docType = $this->table->app()->cfgItem ('e10.docs.types.' . $item ['docType']);
		$docKind = $this->table->app()->cfgItem ('e10.docs.kinds.' . $item ['docKind'], FALSE);

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = $item['docKeyId'];
		$listItem ['i2'] = ['text' => '#'.$item['ndx'], 'class' => 'e10-small e10-id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];
		$props[] = ['text' => $docType['fullName'], 'class' => 'label label-default', 'icon' => 'system/iconFile'];

		if ($item ['docKind'])
			$props[] = ['text' => $docKind['shortName'], 'icon' => 'icon-flag-o', 'class' => 'label label-default'];

		if ($item['tabName'] !== '')
			$props[] = ['text' => $item['tabName'], 'icon' => 'icon-folder-o', 'class' => 'label label-default'];

		if ($item['order'])
			$props[] = ['text' => utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'label label-default'];

		if ($item['firstNumberSet'])
		{
			$fp = $this->app()->cfgItem('e10doc.acc.periods.'.$item['firstNumberFiscalPeriod'], FALSE);
			if ($fp === FALSE)
			{
				$props[] = [
					'text' => 'Chybné účetní období pro první číslo v řadě',
					'icon' => 'icon-flag', 'class' => 'label label-danger'
				];

			}
			else
			{
				$props[] = [
					'text' => $fp['fullName'], 'suffix' => utils::nf($item['firstNumber']),
					'icon' => 'icon-flag', 'class' => 'label label-success'
				];
			}

		}

		$listItem ['t2'] = $props;

		return $listItem;
	}
}


/**
 * Class FormDocNumber
 * @package E10Doc\Base
 */
class FormDocNumber extends TableForm
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
					$this->addColumnInput ('tabName');
					$this->addColumnInput ('docType');
					$this->addColumnInput ('docKeyId');
					$this->addColumnInput ('useDocKinds');
					if (isset($this->recData['useDocKinds']) && $this->recData['useDocKinds'] !== 0)
						$this->addColumnInput ('docKind');
					$this->addColumnInput ('activitiesGroup');
					$this->addColumnInput ('usePersonsOffice');
					$this->addColumnInput ('order');
					$this->addColumnInput ('emailSender');
					if (isset($this->recData['emailSender']) && $this->recData['emailSender'] == 2)
					{
						$this->addColumnInput('emailFromAddress');
						$this->addColumnInput('emailFromName');
					}
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('firstNumberSet');
					if ($this->recData['firstNumberSet'])
					{
						$this->addColumnInput('firstNumber');
						$this->addColumnInput('firstNumberFiscalPeriod');
					}
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}

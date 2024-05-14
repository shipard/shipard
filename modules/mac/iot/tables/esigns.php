<?php

namespace mac\iot;

use \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail;
use \Shipard\Application\DataModel;
use \Shipard\Utils\Json;


/**
 * Class TableESigns
 */
class TableESigns extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.esigns', 'mac_iot_esigns', 'E-cedulky');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function getESignInfo($esignNdx)
	{
		$info = [];
		$info['esignRecData'] = $this->loadItem($esignNdx);
		if (!$info['esignRecData'])
			return NULL;

		$epaperCfg = NULL;

		$ioPortRecData = $this->app()->loadItem($info['esignRecData']['iotPort'], 'mac.iot.devicesIOPorts');
		if ($ioPortRecData)
		{
			$portCfg = Json::decode($ioPortRecData['portCfg']);
			if ($portCfg)
			{
				$epaperCfg = $this->app()->cfgItem('mac.iot.epds.types.'.$portCfg['displayType'], NULL);
				$info['orientation'] = intval($info['esignRecData']['orientation'] ?? 0);
			}
		}
		elseif ($info['esignRecData']['esignKind'])
		{
			$esignKindRecData = $this->app()->loadItem($info['esignRecData']['esignKind'], 'mac.iot.esignsKinds');
			$epaperCfg = $this->app()->cfgItem('mac.iot.epds.types.'.$esignKindRecData['displayType'], NULL);
			$info['orientation'] = intval($esignKindRecData['orientation'] ?? 0);
		}

		if ($epaperCfg)
		{
			$info['ok'] = 1;

			if ($info['orientation'] === 0 || $info['orientation'] === 2)
			{
				$info['width'] = $epaperCfg['width'];
				$info['height'] = $epaperCfg['height'];
			}
			else
			{
				$info['height'] = $epaperCfg['width'];
				$info['width'] = $epaperCfg['height'];
			}
			$info['cntColors'] = $epaperCfg['cntColors'];
			$info['colors'] = $epaperCfg['colors'];

			$info['displayInfoLabel'] = [['text' => $info['width'].' ✖️ '.$info['height'].'; '.$epaperCfg['cntColors'].'C', 'class' => 'label label-info']];
			$cc = "<span style='padding: 2px;'>";
			foreach ($epaperCfg['colors'] as $strColor)
			{
				$cc .= "<span style='width: 1rem; display:inline-block; padding-left: 2px;border: 1px solid #777; background-color: #".$strColor.";'> </span>";
			}

			$cc .= '</span>';

			$info['displayInfoLabel'][] = ['code' => $cc, 'class' => ''];
		}

		return $info;
	}

	public function subColumnsInfo ($recData, $columnId)
	{

		if ($columnId === 'vdsData')
		{
			if (!$recData['esignKind'])
				return FALSE;


			$esignKindRecData = $this->app()->loadItem($recData['esignKind'], 'mac.iot.esignsKinds');

			if (!$esignKindRecData || !isset($esignKindRecData['vds']) || !$esignKindRecData['vds'])
				return FALSE;

			$vds = $this->db()->query ('SELECT * FROM [vds_base_defs] WHERE [ndx] = %i', $esignKindRecData['vds'])->fetch();
			if (!$vds)
				return FALSE;

			$sc = json_decode($vds['structure'], TRUE);
			if (!$sc || !isset($sc['fields']))
				return FALSE;

			return $sc['fields'];
		}

		return parent::subColumnsInfo ($recData, $columnId);
	}
}


/**
 * Class ViewESigns
 */
class ViewESigns extends TableView
{
	public function init ()
	{
		parent::init();
		$this->linesWidth = 28;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'idName'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

    $t2 = [];

    $t2[] = ['text' => $item['idName'], 'class' => 'label label-primary'];

		$displayInfo = $this->table->getESignInfo($item ['ndx']);
		if ($displayInfo && ($displayInfo['ok'] ?? 0))
		{
			$listItem['t3'] = $displayInfo['displayInfoLabel'];
		}

    $listItem['t2'] = $t2;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [esigns].*';
		array_push ($q, ' FROM [mac_iot_esigns] AS [esigns]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [esigns].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[esigns].', ['[esigns].[shortName], [fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormESign
 */
class FormESign extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
    $tabs ['tabs'][] = ['text' => 'Šablona', 'icon' => 'formText'];
    $tabs ['tabs'][] = ['text' => 'CSS', 'icon' => 'formText'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('idName');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('esignKind');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('iotDevice');
					$this->addColumnInput ('iotPort');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('orientation');

					$this->addSeparator(self::coH4);
					if ($this->addSubColumns('vdsData'))
						$this->addSeparator(self::coH4);

				$this->closeTab ();
        $this->openTab (TableForm::ltNone);
          $this->addInputMemo ('codeTemplate', NULL, TableForm::coFullSizeY, DataModel::ctCode);
        $this->closeTab();
        $this->openTab (TableForm::ltNone);
          $this->addInputMemo ('codeStyle', NULL, TableForm::coFullSizeY, DataModel::ctCode);
        $this->closeTab();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * class ViewDetailESign
 */
class ViewDetailESign extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.iot.dc.DCESign');
	}
}


<?php

namespace terminals\ros;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \e10\utils, \e10\json, \E10\TableView, \E10\TableForm, \E10\DbTable, \Shipard\Application\Application;


/**
 * Class TableRosRegs
 * @package terminals\ros
 */
class TableRosRegs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('terminals.ros.rosRegs', 'terminals_ros_rosRegs', 'Registrace k EET');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => ($recData ['title'] != '') ? $recData ['title'] : ' '];
		$hdr ['info'][] = ['class' => 'info', 'value' => $this->certificateInfo($recData)];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
		if (!isset ($recData['certPath']) || $recData['certPath'] === '')
			$recData['certPath'] = utils::createToken(40);
		if (!isset ($recData['certFileName']) || $recData['certFileName'] === '')
			$recData['certFileName'] = utils::createToken(40);
	}

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);
		$this->saveCertificates ($recData);
	}

	function copyDocumentRecord ($srcRecData, $ownerRecord = NULL)
	{
		$recData = parent::copyDocumentRecord ($srcRecData, $ownerRecord);

		$recData ['certPath'] = '';
		$recData ['certFileName'] = '';
		$recData ['certPassword'] = '';

		return $recData;
	}

	public function saveCertificates ($recData)
	{
		$baseCertDir = __APP_DIR__.'/res/ros/';

		$sql = "SELECT * FROM [e10_attachments_files] WHERE [tableid] = %s AND [recid] = %i AND [deleted] = 0 ORDER BY [order], name";
		$query = $this->app()->db->query ($sql, 'terminals.ros.rosRegs', $recData['ndx']);
		foreach ($query as $row)
		{
			if (substr($row['filename'], -4) !== '.p12')
				continue;

			$attFileName = __APP_DIR__.'/att/'.$row['path'].$row['filename'];
			$certDir = $baseCertDir.$recData['certPath'];
			if (!is_dir($certDir))
				mkdir($certDir, 0700, TRUE);
			else
				array_map ('unlink', glob ($certDir.'/'.$recData['certFileName'].'.*'));

			$certFileName = $certDir.'/'.$recData['certFileName'];
			copy($attFileName, $certFileName.'.p12');

			$certs = [];
			$pkcs12 = file_get_contents($attFileName);
			if (openssl_pkcs12_read($pkcs12, $certs, $recData['certPassword']))
			{
				file_put_contents($certFileName.'.crt', $certs['cert']);
				file_put_contents($certFileName.'.pem', $certs['pkey']);

				$ccd = openssl_x509_parse($certs['cert'],0);
				file_put_contents($certFileName.'.test', json::lint($ccd));
			}
			else
			{
				file_put_contents($certFileName.'.test', json::lint(['error' => 'Chybný certifikát nebo heslo']));
			}

			break;
		}
	}

	public function saveConfig ()
	{
		// -- create cfg file
		$list = [];
		$list ['0'] = ['ndx' => 0, 'title' => '----'];

		$rows = $this->app()->db->query ('SELECT * FROM [terminals_ros_rosRegs] WHERE [docState] != 9800 ORDER BY [ndx]');

		foreach ($rows as $r)
		{
			$rr = [
					'ndx' => $r ['ndx'], 'title' => $r ['title'], 'rosType' => $r['rosType'], 'rosMode' => $r['rosMode'],
					'placeId' => $r['placeId'], 'vatIdPrimary' => $r['vatIdPrimary'],
					'certPath' => $r['certPath'].'/'.$r['certFileName'], 'certPassword' => $r['certPassword']
			];

			if ($r['validFrom'])
				$rr['validFrom'] = $r['validFrom']->format('Y-m-d');
			if ($r['validTo'])
				$rr['validTo'] = $r['validTo']->format('Y-m-d');

			$list [$r['ndx']] = $rr;
		}

		// -- save to file
		$cfg ['terminals']['ros']['regs'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_terminals.ros.regs.json', utils::json_lint (json_encode ($cfg)));
	}

	function certificateInfo ($item)
	{
		$rosMode = $item['rosMode'];

		$isDemo = ($this->app()->model()->module ('demo.core') !== FALSE);
		$dsMode = $this->app()->cfgItem ('dsMode', 0);
		if (($dsMode != Application::dsmProduction && $rosMode === 0) || $isDemo)
			$rosMode = 2; // non production data source or demo

		if ($rosMode == 2)
		{ // testing/demo ==> playground
			$crtPath = __SHPD_ROOT_DIR__ . '/src/_deprecated/lib/'.'ros/cz/res/playground/cert';

			$certs = [];
			$certs['cert'] = file_get_contents($crtPath.'.crt');
			$certs['pkey'] = file_get_contents($crtPath.'.pem');
			$crtInfo = openssl_x509_parse($certs['cert'], 0);

			return [
				'status' => 1,
				'labels' => [
					['text' => 'DEMO', 'class' => 'label label-success', 'icon' => 'icon-certificate'],
					['text' => $crtInfo['subject']['commonName'], 'suffix' => $crtInfo['subject']['description'], 'class' => 'label label-success', 'icon' => 'icon-address-card-o'],
					['text' => $crtInfo['issuer']['commonName'], 'suffix' => $crtInfo['issuer']['organizationName'], 'class' => 'label label-success', 'icon' => 'icon-feed']
				]
			];
		}

		$certTestFileName = __APP_DIR__.'/res/ros/'.$item['certPath'].'/'.$item['certFileName'].'.test';
		if (!is_readable($certTestFileName))
		{
			return [
				'status' => 0,
				'msg' => 'Nelze načíst certifikát - patrně není správně nahraný',
				'labels' => [
					['text' => 'Není nahrán certifikát', 'class' => 'label label-danger', 'icon' => 'system/iconWarning']
				]
			];
		}

		$crtInfo = utils::loadCfgFile($certTestFileName);
		if (!$crtInfo)
		{
			return [
				'status' => 0,
				'msg' => 'Nelze načíst informace o certifikátu',
				'labels' => [
					['text' => 'Neznámá chyba', 'class' => 'label label-danger', 'icon' => 'system/iconWarning']
				]
			];
		}

		if (isset($crtInfo['error']))
		{
			return [
				'status' => 0,
				'msg' => 'Problém s certifikátem: '.$crtInfo['error'],
				'labels' => [
					['text' => $crtInfo['error'], 'class' => 'label label-danger', 'icon' => 'system/iconWarning']
				]
			];
		}

		$status = 1;
		$msg = '';
		$now = new \DateTime();
		$validTo = new \DateTime('@'.$crtInfo['validTo_time_t']);
		$daysBeforeExpire = utils::dateDiff($now, $validTo);
		$validToLabel = ['text' => utils::datef($validTo, '%d, %T'), 'icon' => 'system/iconCalendar', 'class' => 'label label-success'];

		if ($validTo <= $now)
		{
			$validToLabel['class'] = 'label label-danger';
			$status = 0;
			$msg = 'Platnost certifikátu vypršela';
		}
		elseif ($daysBeforeExpire < 30)
		{
			$status = 0;
			$msg = 'Certifikátu brzy vyprší platnost';
			$validToLabel['class'] = 'label label-warning';
		}

		$res = [
			'validTo' => utils::datef($validTo, '%d, %T'),
			'status' => $status,
			'msg' => $msg,
			'labels' => [
				$validToLabel,
				['text' => 'OK', 'class' => 'label label-success', 'icon' => 'icon-certificate'],
				['text' => $crtInfo['subject']['commonName'], 'suffix' => $crtInfo['subject']['description'] ?? '', 'class' => 'label label-success', 'icon' => 'icon-address-card-o'],
				['text' => $crtInfo['issuer']['commonName'], 'suffix' => $crtInfo['issuer']['organizationName'], 'class' => 'label label-success', 'icon' => 'icon-feed']
			]
		];

		return $res;
	}
}


/**
 * Class ViewRosRegs
 * @package terminals\ros
 */
class ViewRosRegs extends TableView
{
	var $rosTypes;
	var $rosModes;

	public function init ()
	{
		$this->rosTypes = $this->app()->cfgItem('terminals.ros.types');
		$this->rosModes = $this->app()->cfgItem('terminals.ros.modes');

		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $this->rosTypes[$item['rosType']]['name'];
		$listItem ['i1'] = $item['placeId'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t2'] = $item['title'];

		$props = [];

		$mode = $this->rosModes[$item['rosMode']];
		$props[] = ['icon' => $mode['icon'], 'text' => $mode['name'], 'class' => 'label '.$mode['lc']];

		if ($item['validFrom'] || $item['validTo'])
		{
			$txt = '';
			if ($item['validFrom'])
				$txt = utils::datef ($item['validFrom']);
			$txt .= ' → ';
			if ($item['validTo'])
				$txt = utils::datef ($item['validTo']);
			$props[] = ['icon' => 'system/iconCalendar', 'text' => $txt, 'class' => 'label label-primary'];
		}

		$listItem ['i2'] = $props;

		$ci =  $this->table->certificateInfo ($item);
		$listItem ['t3'] = $ci['labels'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [terminals_ros_rosRegs]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
					' [title] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[rosType], [ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormTaxReg
 * @package E10Doc\Base
 */
class FormRosReg extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
		$this->openForm ();
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('rosType');
					$this->addColumnInput ('rosMode');
					$this->addColumnInput ('title');
					$this->addColumnInput ('vatIdPrimary');
					$this->addColumnInput ('placeId');
					$this->addColumnInput ('certPassword');
					$this->addColumnInput ('validFrom');
					$this->addColumnInput ('validTo');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


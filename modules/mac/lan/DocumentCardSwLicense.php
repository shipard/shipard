<?php

namespace mac\lan;
use \e10\base\libs\UtilsBase;
use \e10\utils;

/**
 * Class DocumentCardSwLicense
 * @package mac\lan
 */
class DocumentCardSwLicense extends \e10\DocumentCard
{
	var $tableDevices;
	var $devices = [];

	public function createContentHeader ()
	{
		$title = ['icon' => $this->table->tableIcon ($this->recData), 'text' => $this->recData ['fullName']];
		$this->addContent('title', ['type' => 'line', 'line' => $title]);
		//$this->addContent('subTitle', ['type' => 'line', 'line' => '#'.$this->recData ['id']]);
	}

	public function createContent_Properties ()
	{
		$table = [];

		if ($this->recData['id'] !== '')
			$table[] = ['property' => 'Ev. číslo', 'value' => $this->recData['id']];

		if ($this->recData['validFrom'] || $this->recData['validTo'])
		{
			$v = '';
			if (!utils::dateIsBlank($this->recData['validFrom']))
				$v .= utils::datef($this->recData['validFrom']);
			$v .= ' → ';
			if (!utils::dateIsBlank($this->recData['validTo']))
				$v .= utils::datef($this->recData['validTo']);
			$table[] = ['property' => 'Platnost', 'value' => $v];
		}

		if ($this->recData['maxUsers'] || $this->recData['maxDevices'])
		{
			$v = [];
			if ($this->recData['maxUsers'])
				$v[] = ['text' => utils::nf($this->recData['maxUsers']).' uživatel(ů) ', 'prefix' => 'max.'];
			if ($this->recData['maxDevices'])
				$v[] = ['text' => utils::nf($this->recData['maxDevices']).' počítač(ů) ', 'prefix' => 'max.'];
			$table[] = ['property' => 'Rozsah', 'value' => $v];
		}

		if ($this->recData['invoiceNumber'] !== '')
			$table[] = ['property' => 'Doklad pořízení', 'value' => $this->recData['invoiceNumber']];

		if ($this->recData['licenseNumber'] !== '')
			$table[] = ['property' => 'Licenční číslo', 'value' => $this->recData['licenseNumber']];

		// -- users
		$linkedPersons = UtilsBase::linkedPersons ($this->app, $this->table, $this->recData['ndx']);
		if (count($linkedPersons))
			$table[] = ['property' => 'Uživatelé', 'value' => $linkedPersons];

		// -- devices
		$linkedDevices = $this->linkedDevices($this->app, $this->table, $this->recData['ndx']);
		if (count($linkedDevices))
			$table[] = ['property' => 'Počítače', 'value' => $linkedDevices];

		$h = ['property' => '_Vlastnost', 'value' => 'Hodnota'];

		$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
				'header' => $h, 'table' => $table,
				'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']]);
	}

	public function createContentBody ()
	{
		$this->createContent_Properties();
		$this->addContentAttachments ($this->recData ['ndx']);
	}

	public function createContent ()
	{
		$this->tableDevices = $this->app()->table ('mac.lan.devices');

		$this->createContentHeader ();
		$this->createContentBody ();
	}

	function linkedDevices ($app, $table, $toRecId, $elementClass = '')
	{
		if (is_string($table))
			$tableId = $table;
		else
			$tableId = $table->tableId ();

		$links = $app->cfgItem ('e10.base.doclinks', NULL);

		if (!$links)
			return [];
		if (!isset($links [$tableId]))
			return [];
		$allLinks = $links [$tableId];

		$lp = [];

		$recId = intval($toRecId);
		$q[] = 'SELECT links.ndx, links.linkId AS linkId, links.srcRecId AS srcRecId, links.dstRecId AS dstRecId, devices.fullName AS fullName';
		array_push($q, ' FROM e10_base_doclinks AS links');
		array_push($q, ' LEFT JOIN [mac_lan_devices] as [devices] ON links.dstRecId = [devices].ndx');
		array_push($q, ' WHERE srcTableId = %s', $tableId, ' AND dstTableId = %s', 'mac.lan.devices', ' AND links.srcRecId = %i', $recId);
		array_push($q, ' AND devices.[docStateMain] = %i', 2);
		$query = $app->db->query ($q);

		foreach ($query as $r)
		{
			$icon = 'deviceTypes/workStation';
			if (isset ($allLinks [$r['linkId']]['icon']))
				$icon = $allLinks [$r['linkId']]['icon'];
			if (isset ($lp [$r['srcRecId']][$r['linkId']]))
				$lp [$r['srcRecId']][$r['linkId']][0]['text'] .= ', '.$r ['fullName'];
			else
				$lp [$r['srcRecId']][$r['linkId']][0] = ['icon' => $icon, 'text' => $r ['fullName'], 'class' => $elementClass];
			if ($r['dstRecId'])
				$lp [$r['srcRecId']][$r['linkId']][0]['pndx'][] = $r['dstRecId'];
		}

		return $lp;
	}
}

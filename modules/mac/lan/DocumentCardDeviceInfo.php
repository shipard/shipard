<?php

namespace mac\lan;

use E10\utils;


/**
 * Class DocumentCardDeviceInfo
 * @package mac\lan
 */
class DocumentCardDeviceInfo extends \e10\DocumentCard
{
	var $ports = [];

	public function createContentHeader ()
	{
		$title = ['icon' => $this->table->tableIcon ($this->recData), 'text' => $this->recData ['fullName']];
		$this->addContent('title', ['type' => 'line', 'line' => $title]);
		$this->addContent('subTitle', ['type' => 'line', 'line' => '#'.$this->recData ['id']]);
	}

	function bytesToSize1024($bytes, $precision = 1)
	{
		if ($bytes == 0)
			return '';
		$unit = array('B','KB','MB','GB','TB','PB','EB');
		return @round($bytes / pow(1024, ($i = floor(log($bytes, 1024)))), $precision).' '.$unit[$i];
	}

	protected function createInfoDrives ($data, $row)
	{
		$date = ($row['dateUpdate']) ? $row['dateUpdate'] : $row['dateCreate'];
		$title = [['icon' => 'icon-hdd-o', 'text' => 'Disky', 'suffix' => utils::datef($date, '%D, %T')]];

		$table = [];
		foreach ($data['items'] as $item)
		{
			$table[] = ['name' => $item['name'], 'type' => $item['type'], 'bus' => $item['bus'], 'status' => $item['status']];
		}

		$h = ['#' => '#', 'name' => 'Název', 'type' => 'Typ', 'bus' => 'Sběrnice', 'status' => ' Status'];
		$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'title' => $title,
			'header' => $h, 'table' => $table]);
	}

	protected function createInfoStorage ($data, $row)
	{
		$date = ($row['dateUpdate']) ? $row['dateUpdate'] : $row['dateCreate'];
		$title = [['icon' => 'icon-folder-open-o', 'text' => 'Úložiště', 'suffix' => utils::datef($date, '%D, %T')]];

		$table = [];
		foreach ($data['items'] as $item)
		{
			if (!$item['size'])
				continue;
			$table[] = ['name' => $item['name'], 'size' => $this->bytesToSize1024($item['size']), 'used' => $this->bytesToSize1024($item['used'])];
		}

		$h = ['#' => '#', 'name' => 'Název', 'size' => ' Velikost', 'used' => ' Využito'];
		$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
				'title' => $title,
				'header' => $h, 'table' => $table]);
	}

	protected function createInfoCounters ($data, $row)
	{
		$counters = [
			'p-pp-all' => 'Celkový počet vytištěných stránek',
			'p-pp-clr' => 'Počet barevně vytištěných stránek',
			'p-pp-2c'  => 'Počet dvoubarevně vytištěných stránek',
			'p-pp-bw'  => 'Počet černobíle vytištěných stránek',
			'p-cp-clr' => 'Počet barevně zkopírovaných stránek',
			'p-cp-2c'  => 'Počet dvoubarevně zkopírovaných stránek',
			'p-cp-bw'  => 'Počet černobíle zkopírovaných stránek',
			'p-sp-all' => 'Celkový počet naskenovaných stránek'
		];

		$date = ($row['dateUpdate']) ? $row['dateUpdate'] : $row['dateCreate'];
		$title = [['icon' => 'icon-spinner', 'text' => 'Počitadla', 'suffix' => utils::datef($date, '%D, %T')]];

		$table = [];
		foreach ($data['items'] as $item)
		{
			if (!$item['val'])
				continue;

			$name = isset($counters[$item['id']]) ? $counters[$item['id']] : $item['id'];

			$table[] = ['name' => $name, 'val' => utils::nf($item['val'])];
		}

		$h = ['#' => '#', 'name' => 'Název', 'val' => ' Hodnota'];
		$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'title' => $title,
			'header' => $h, 'table' => $table]);
	}

	protected function swName ($s)
	{
		if ($s[2] !== ':')
			return $s;

		$parts = explode (':', $s);
		if (implode(':', $parts) !== $s)
			return $s;

		$name = '';

		foreach ($parts as $p)
		{
			$name .= hex2bin($p);
		}
		$name = iconv('CP1250', 'UTF-8', $name);
		return $name;
	}

	protected function createInfoSw ($data, $row)
	{
		$date = ($row['dateUpdate']) ? $row['dateUpdate'] : $row['dateCreate'];
		$title = [['icon' => 'system/actionDownload', 'text' => 'Software', 'suffix' => utils::datef($date, '%D, %T')]];

		$table = [];
		foreach ($data['items'] as $item)
		{
			$n = $this->swName($item['name']);
			$table[] = ['name' => $n, 'date' => utils::datef($item['date'], '%D, %T')];
		}

		$h = ['#' => '#', 'name' => 'Název', 'date' => 'Datum'];
		$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
				'title' => $title,
				'header' => $h, 'table' => $table]);
	}

	protected function createInfoSystem ($data, $row)
	{
		$names = [
			'name' => 'Název',
			'desc' => 'Popis',
			'version-os' => 'Verze OS',
			'version-fw' => 'Verze FW',
			'device-type' => 'Typ',
			'device-arch' => 'Architektura',
			'device-sn' => 'Výrobní číslo',
		];

		$date = ($row['dateUpdate']) ? $row['dateUpdate'] : $row['dateCreate'];
		$title = [['icon' => 'system/detailInfo', 'text' => 'Informace o systému', 'class' => 'header', 'suffix' => utils::datef($date, '%D, %T')]];

		$table = [];
		foreach ($data['items'] as $key => $value)
		{
			$table[] = [
				'name' => isset($names[$key]) ? $names[$key] : $key,
				'value' => $value
			];
		}

		$h = ['name' => 'Název', 'value' => 'Text'];
		$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
				'title' => $title,
				'header' => $h, 'table' => $table, 'params' => ['hideHeader' => 1]]);
	}

	public function createContentBody ()
	{
		$rows = $this->db()->query ('SELECT * FROM [mac_lan_devicesInfo] WHERE [device] = %i', $this->recData['ndx']);

		$info = [];
		foreach ($rows as $row)
			$info[$row['infoType']] = $row->toArray();

		$infoList = ['system', 'drives', 'storages', 'sw', 'counters'];
		foreach ($infoList as $infoType)
		{
			if (!isset($info[$infoType]))
				continue;

			$r = $info[$infoType];
			$data = json_decode($r['data'], TRUE);

			if ($r['infoType'] === 'storages')
				$this->createInfoStorage($data, $r);
			elseif ($r['infoType'] === 'drives')
				$this->createInfoDrives($data, $r);
			elseif ($r['infoType'] === 'sw')
				$this->createInfoSw($data, $r);
			elseif ($r['infoType'] === 'system')
				$this->createInfoSystem($data, $r);
			elseif ($r['infoType'] === 'counters')
				$this->createInfoCounters($data, $r);
			else
				$this->addContent ('body',
						[
								'pane' => 'e10-pane e10-pane-table', 'type' => 'line',
								'title' => $r['infoType'], 'line' => ['text' => $r['data'], 'class' => 'block']
						]
				);
		}
	}

	public function createContent ()
	{
		$this->createContentHeader ();
		$this->createContentBody ();
	}
}

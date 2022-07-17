<?php

namespace services\sw\dc;

use e10\utils, e10\json;


/**
 * Class SW
 * @package mac\swlan\dc
 */
class SW extends \e10\DocumentCard
{
	/** @var \mac\sw\TablePublishers */
	var $tablePublishers;

	/** @var \mac\swcore\libs\SWUtils */
	var $swUtils;

	function addIntro()
	{
		$t = [];

		// -- name
		$t[] = [
			'c1' => 'Název',
			'c2' => $this->recData['fullName']
		];

		// -- swClass
		$swClass = $this->app()->cfgItem('mac.swcore.swClass.'.$this->recData['swClass']);
		$t[] = [
			'c1' => 'Druh',
			'c2' => ['text' => $swClass['fn'], 'icon' => $swClass['icon']]
		];

		// -- publisher
		if ($this->recData['publisher'])
		{
			$publisher = $this->tablePublishers->loadItem($this->recData['publisher']);
			$t[] = [
				'c1' => 'Vydavatel',
				'c2' => ['text' => $publisher['fullName']],
			];
		}

		// -- free use
		$swFreeUse = $this->app()->cfgItem('mac.swcore.swUseFree.'.$this->recData['useFree'], NULL);
		if ($swFreeUse)
		{
			$t[] = [
				'c1' => 'Volné použití',
				'c2' => ['text' => $swFreeUse['fn']],
			];
		}

		// -- licence
		$swLicenceType = $this->app()->cfgItem('mac.swcore.swLicenceType.'.$this->recData['licenseType'], NULL);
		if ($swLicenceType)
		{
			$t[] = [
				'c1' => 'Forma licence',
				'c2' => ['text' => $swLicenceType['fn']],
			];
		}

		// - links
		$annots = new \e10pro\kb\libs\AnnotationsList($this->app());
		$annots->addRecord($this->table->ndx, $this->recData['ndx']);
		$annots->load();
		foreach ($annots->data as $a)
		{
			$link = [
				'c1' => $a['title'],
				'c2' => ['text' => $a['url'], 'url' => $a['url']],
			];

			if ($a['linkLanguageCountry'])
				$link['c1'] .= ' '.$a['linkLanguageCountry']['f'];

			$t[] = $link;
		}


		$h = ['c1' => '_c1', 'c2' => 'c2'];
		$this->addContent('body', [
			'pane' => 'e10-pane e10-pane-table e10-pane-top', 'type' => 'table', 'table' => $t, 'header' => $h,
			'params' => ['forceTableClass' => 'properties fullWidth', 'hideHeader' => 1],
			'_title' => ['text' => 'Detaily certifikátu', 'class' => 'h2']
		]);
	}

	function addVersions()
	{
		$q [] = 'SELECT [versions].*';
		array_push ($q, ' FROM [mac_sw_swVersions] AS [versions]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [versions].[sw] = %i', $this->recData['ndx']);
		array_push ($q, ' ORDER BY [versions].[versionOrderId] DESC, [versions].[versionNumber] DESC, [versions].[ndx] DESC');

		$table = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'version' => $r['versionNumber'],
				'name' => [['text' => $r['versionName'], 'class' => 'block nowrap']],
				'lifeCycle' => []
			];

			if ($r['versionNameShort'] !== '' && $r['versionNameShort'] !== $r['versionName'])
				$item ['name'][] = ['text' => $r['versionNameShort'], 'class' => 'e10-off'];

			if ($r['lifeCycle'])
				$this->swUtils->lcLabel($r['lifeCycle'], $item ['lifeCycle']);

			if (!utils::dateIsBlank($r['dateRelease']))
				$item ['dateRelease']= utils::datef($r['dateRelease'], '%d');
			if (!utils::dateIsBlank($r['dateObsolete']))
				$item ['dateObsolete'] = utils::datef($r['dateObsolete'], '%d');
			if (!utils::dateIsBlank($r['dateEndSupport']))
				$item ['dateEnd'] = utils::datef($r['dateEndSupport'], '%d');

			$table[] = $item;
		}

		$header = [
			'version' => 'Verze',
			'name' => 'Název',
			'lifeCycle' => 'Stav',
			'dateRelease' => 'Zveřejněno',
			'dateObsolete' => 'Zastaralé',
			'dateEnd' => 'Ukončeno',
		];

		$this->addContent('body', [
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'table' => $table, 'header' => $header,
			'__params' => ['hideHeader' => 1],
			'title' => ['text' => 'Verze', 'class' => 'h2']
		]);

	}


	public function createContentBody ()
	{
		$this->addIntro();
		$this->addVersions();
	}

	public function createContent ()
	{
		$this->swUtils = new \mac\swcore\libs\SWUtils($this->app());
		$this->tablePublishers = $this->app()->table('mac.sw.publishers');

		//$this->createContentHeader ();
		$this->createContentBody ();
	}
}

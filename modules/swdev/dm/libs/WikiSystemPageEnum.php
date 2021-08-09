<?php

namespace swdev\dm\libs;
use \Shipard\Utils\TableRenderer, \e10\utils, \e10\json;


/**
 * Class WikiSystemPageEnum
 * @package swdev\dm\libs
 */
class WikiSystemPageEnum extends \e10pro\kb\libs\SystemPageEngine
{
	/** @var \swdev\dm\TableEnums */
	var $tableEnums;
	var $ownerText = 0;

	function init()
	{
		$this->ownerText = intval($this->app()->cfgItem ('options.swdevDocumentation.wikiPageEnums', 0));
		$this->tableEnums = $this->app()->table('swdev.dm.enums');

		parent::init();
	}

	public function regenerateAllPages()
	{
		echo "-- generating wiki pages for ENUMS...\n";

		if (!$this->ownerText)
			return;

		$q[] = 'SELECT [enums].*';
		array_push($q, ' FROM [swdev_dm_enums] AS [enums]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [enums].docState = %i', 4000);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			echo " - ".$r['name'].': '.$r['id']."\n";
			$this->regenerateOnePage($r);
		}
	}

	function regenerateOnePage($enumRecData)
	{
		/** @var \e10pro\kb\TableTexts $tableTexts */
		$tableTexts = $this->app()->table('e10pro.kb.texts');

		$exist = $this->searchPage('swdev-dm-enum', 0, 1320, $enumRecData['ndx']);
		if ($exist)
		{
			$update['title'] = $enumRecData['id'];
			$update['subTitle'] = $enumRecData['name'].' #'.$enumRecData['ndx'];
			$update['docState'] = 4000;
			$update['docStateMain'] = 2;

			$wikiPageNdx = $exist['ndx'];
			$this->db()->query('UPDATE [e10pro_kb_texts] SET ', $update, ' WHERE [ndx] = %i', $wikiPageNdx);
		}
		else
		{
			$newPage = $this->createPage('swdev-dm-enum', $this->ownerText, 1320, $enumRecData['ndx']);
			$newPage['title'] = $enumRecData['id'];
			$newPage['subTitle'] = $enumRecData['name'].' #'.$enumRecData['ndx'];
			$wikiPageNdx = $tableTexts->dbInsertRec($newPage);
			$tableTexts->docsLog($wikiPageNdx);
		}

		if ($enumRecData['dmWikiPage'] == 0)
		{
			$this->db()->query('UPDATE [swdev_dm_enums] SET [dmWikiPage] = %i', $wikiPageNdx, ' WHERE [ndx] = %i', $enumRecData['ndx']);
		}

		$wikiPageRecData = $tableTexts->loadItem($wikiPageNdx);
		$tableTexts->checkAfterSave2($wikiPageRecData);
	}

	public function renderPage($pageRecData)
	{
		$enumRecData = $this->tableEnums->loadItem($pageRecData['srcRecNdx']);

		$text = $pageRecData['text'];

		//$text .= 'tady to bude...';
		$text .= $this->enumValuesCode($pageRecData, $enumRecData);

		return $text;
	}

	function enumValuesCode($pageRecData, $enumRecData)
	{
		$enumNdx = $pageRecData['srcRecNdx'];
		$fullData = [];

		$h = ['_v' => ' Hodn.'];
		$t = [];

		$config = json_decode($enumRecData['config'], TRUE);

		if (isset($config['textsIds']))
		{
			foreach ($config['textsIds'] as $columnId => $columnTitle)
				$h[$columnId] = $columnTitle;
		}

		$c = '';

		if ($pageRecData['text'] !== '')
			$c .= '<hr>';
		$c .= "<h4>".utils::es('Hodnoty').'</h4>';


		$q[] = 'SELECT [values].* FROM [swdev_dm_enumsValues] AS [values]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [values].[enum] = %i', $enumNdx);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$value = $r['value'];
			$columnId = $r['columnId'];
			if (!isset($t[$value]))
				$t[$value] = ['_v' => strval($value)];
			if (!isset($t[$value][$columnId]))
				$t[$value][$columnId] = [];

			$t[$value][$columnId][] = ['text' => $r['text'], 'class' => ''];

			if (!isset($fullData[$value]))
			{
				$valueData = json_decode($r['data'], TRUE);
				$fullData[$value] = $valueData;
			}
		}

		/*
		$this->addContent ('body',
			[
				'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
				'header' => $h, 'table' => $t, 'main' => TRUE,
			]);
		*/

		$tr = new TableRenderer($t, $h, [], $this->app());
		$c .= $tr->render();

		$c .= "<h4>".utils::es('Data').'</h4>';

		$c .= "\n\n/---code json\n";
		$c .= json::lint($fullData);
		$c .= "\n\\---\n\n";

		return $c;
	}
}

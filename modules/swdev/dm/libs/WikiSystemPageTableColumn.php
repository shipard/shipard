<?php

namespace swdev\dm\libs;


/**
 * Class WikiSystemPageTableColumn
 * @package swdev\dm\libs
 */
class WikiSystemPageTableColumn extends \e10pro\kb\libs\SystemPageEngine
{
	/** @var \swdev\dm\TableColumns */
	var $tableColumns;

	var $ownerText = 0;

	function init()
	{
		$this->ownerText = intval($this->app()->cfgItem ('options.swdevDocumentation.wikiPageEnums', 0));
		$this->tableColumns = $this->app()->table('swdev.dm.columns');

		parent::init();
	}

	function regenerateOnePage($columnRecData)
	{
		/** @var \e10pro\kb\TableTexts $tableTexts */
		$tableTexts = $this->app()->table('e10pro.kb.texts');

		$exist = $this->searchPage('swdev-dm-column', $this->ownerText, 1301, $columnRecData['ndx']);
		if ($exist)
		{
			$wikiPageNdx = $exist['ndx'];
			$update['subTitle'] = $columnRecData['id'].' #'.$columnRecData['ndx'];
			$update['docState'] = 4000;
			$update['docStateMain'] = 2;

			$this->db()->query('UPDATE [e10pro_kb_texts] SET ', $update, ' WHERE [ndx] = %i', $wikiPageNdx);
		}
		else
		{
			$newPage = $this->createPage('swdev-dm-column', $this->ownerText, 1301, $columnRecData['ndx']);
			$newPage['title'] = $columnRecData['name'];
			$newPage['id'] = $columnRecData['id'];
			$newPage['subTitle'] = $columnRecData['id'].' #'.$columnRecData['ndx'];
			$wikiPageNdx = $tableTexts->dbInsertRec($newPage);
			$tableTexts->docsLog($wikiPageNdx);
		}

		if ($columnRecData['dmWikiPage'] == 0)
		{
			$this->db()->query('UPDATE [swdev_dm_columns] SET [dmWikiPage] = %i', $wikiPageNdx, ' WHERE [ndx] = %i', $columnRecData['ndx']);
		}

		$wikiPageRecData = $tableTexts->loadItem($wikiPageNdx);
		$tableTexts->checkAfterSave2($wikiPageRecData);
	}

	public function renderPage($pageRecData)
	{
		$columnRecData = $this->tableColumns->loadItem($pageRecData['srcRecNdx']);

		$text = $pageRecData['text'];

		$text .= "### Definice\n";
		$text .= $this->jsonDef($pageRecData, $columnRecData);

		return $text;
	}

	function jsonDef($pageRecData, $columnRecData)
	{
		$txt = '';
		$txt .= "/---code\n";
		$txt .= $columnRecData['jsonDef'];
		$txt .= "\n\\---\n";

		return $txt;
	}

	public function regenerateTableColumns($tableNdx, $tableWikiPageNdx)
	{
		$this->ownerText = $tableWikiPageNdx;

		$q[] = 'SELECT [cols].*';
		array_push($q, ' FROM [swdev_dm_columns] AS [cols]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [cols].[table] = %i', $tableNdx);
		array_push($q, ' AND [cols].docState = %i', 4000);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->regenerateOnePage($r);
		}
	}
}

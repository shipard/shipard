<?php

namespace swdev\dm\libs;
use E10\DataModel, \e10\utils;


/**
 * Class WikiSystemPageTable
 * @package swdev\dm\libs
 */
class WikiSystemPageTable extends \e10pro\kb\libs\SystemPageEngine
{
	var $ownerText = 0;

	var $columnsOptions = [
		'mandatory' => ['o' => DataModel::coMandatory, 'title' => 'Vyžadováno', 'icon' => 'icon-check-square-o'],
		'saveOnChange' => ['o' => DataModel::coSaveOnChange, 'title' => 'Uložit při změně', 'icon' => 'system/actionDownload'],
		'ascii' => ['o' => DataModel::coAscii, 'title' => 'Pouze ASCII znaky', 'icon' => 'icon-code'],
		'scanner' => ['o' => DataModel::coScanner, 'title' => 'Ze skeneru čár. kódů', 'icon' => 'icon-barcode'],
		'computed' => ['o' => DataModel::coComputed, 'title' => 'Automaticky vypočítáno', 'icon' => 'system/iconCogs'],
		'ui' => ['o' => DataModel::coUserInput, 'title' => 'Zadáváno uživatelem', 'icon' => 'icon-keyboard-o'],
	];

	function init()
	{
		$this->ownerText = intval($this->app()->cfgItem ('options.swdevDocumentation.wikiPageTables', 0));

		parent::init();
	}

	public function regenerateAllPages()
	{
		echo "-- generating wiki pages for TABLES...\n";

		if (!$this->ownerText)
			return;

		$q[] = 'SELECT [tbls].*';
		array_push($q, ' FROM [swdev_dm_tables] AS [tbls]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [tbls].docState = %i', 4000);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			echo " - ".$r['id'].': '.$r['name']."\n";
			$this->regenerateOnePage($r);
		}
	}

	function regenerateOnePage($tableRecData)
	{
		/** @var \e10pro\kb\TableTexts $tableTexts */
		$tableTexts = $this->app()->table('e10pro.kb.texts');

		$exist = $this->searchPage('swdev-dm-table', $this->ownerText, 1188, $tableRecData['ndx']);
		if ($exist)
		{
			$update['title'] = $tableRecData['name'];
			$update['id'] = $tableRecData['id'];
			$update['subTitle'] = $tableRecData['id'].' #'.$tableRecData['ndx'];
			$update['icon'] = $tableRecData['icon'];
			$update['docState'] = 4000;
			$update['docStateMain'] = 2;

			$wikiPageNdx = $exist['ndx'];
			$this->db()->query('UPDATE [e10pro_kb_texts] SET ', $update, ' WHERE [ndx] = %i', $wikiPageNdx);
		}
		else
		{
			$newPage = $this->createPage('swdev-dm-table', $this->ownerText, 1188, $tableRecData['ndx']);
			$newPage['title'] = $tableRecData['name'];
			$newPage['id'] = $tableRecData['id'];
			$newPage['subTitle'] = $tableRecData['id'].' #'.$tableRecData['ndx'];
			$newPage['icon'] = $tableRecData['icon'];
			$wikiPageNdx = $tableTexts->dbInsertRec($newPage);
			$tableTexts->docsLog($wikiPageNdx);
		}

		if ($tableRecData['dmWikiPage'] == 0)
		{
			$this->db()->query('UPDATE [swdev_dm_tables] SET [dmWikiPage] = %i', $wikiPageNdx, ' WHERE [ndx] = %i', $tableRecData['ndx']);
		}

		$colsEngine = new \swdev\dm\libs\WikiSystemPageTableColumn($this->app());
		$colsEngine->init();
		$colsEngine->regenerateTableColumns($tableRecData['ndx'], $wikiPageNdx);

		$wikiPageRecData = $tableTexts->loadItem($wikiPageNdx);
		$tableTexts->checkAfterSave2($wikiPageRecData);
	}

	public function renderPage($pageRecData)
	{
		$text = $pageRecData['text'];

		$text .= $this->tableColumnsCode($pageRecData);

		//$text .= "\n\n`já jsem tabulka a budu tady bydlet`";

		return $text;
	}


	function tableColumnsCode($pageRecData)
	{
		$tableNdx = $pageRecData['srcRecNdx'];
		$c = '';

		if ($pageRecData['text'] !== '')
			$c .= '<hr>';
		$c .= "<h4>".utils::es('Sloupce tabulky').'</h4>';

		$c .= "<table class='default fullWidth'>";

		$c .= "<thead>";
		$c .= "<tr>";

		$c .= "<th class='number'>".utils::es('#').'</th>';
		$c .= "<th>".utils::es('Název').'</th>';
		$c .= "<th>".utils::es('id').'</th>';
		$c .= "<th>".utils::es('Typ').'</th>';
		$c .= "<th>".utils::es('Další info').'</th>';

		$c .= "</tr>";
		$c .= "</thead>";


		$q[] = 'SELECT [cols].*';
		array_push($q, ' FROM [swdev_dm_columns] AS [cols]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [cols].[table] = %i', $tableNdx);
		array_push($q, ' AND [cols].docState = %i', 4000);

		$rows = $this->db()->query($q);
		$colNumber = 1;
		foreach ($rows as $r)
		{
			$c .= "<tr>";


			$c .= "<td class='number'>";
			$c .= utils::nf($colNumber).'.';
			$c .= '</td'>


			$columnUrl = $r['dmWikiPage'];
			$c .= "<td>";
			$c .= "<a href='$columnUrl'>".utils::es($r['name']).'</a>';
			$c .= '</td'>

			$c .= "<td>";
			$c .= utils::es($r['id']);
			$c .= '</td'>

			$c .= "<td>";
			$c .= $this->tableColumnsCode_ColType($r);
			$c .= '</td'>

			$c .= "<td>";
			$c .= $this->tableColumnsCode_Reference($r);
			$c .= '</td'>


			$c .= "</tr>";

			$colNumber++;
		}


		$c.= '</table>';


		return $c;
	}

	function tableColumnsCode_ColType($col)
	{
		$c = '';

		$c .= utils::es($col['colTypeId']);

		if ($col['colTypeId'] === 'string' || $col['colTypeId'] === 'enumString')
			$c .= ' ['.$col['colTypeLen'].']';
		elseif ($col['colTypeId'] === 'number')
			$c .= ' ['.$col['colTypeLen'].'.'.$col['colTypeDec'].']';
		elseif ($col['colTypeId'] === 'enumInt' && $col['colTypeLen'])
			$c .= ' ['.$col['colTypeLen'].']';

		$colDef = json_decode($col['jsonDef'], TRUE);
		if ($colDef && isset($colDef['options']))
		{
			$c .= " <span class='pull-right' style='font-size: 90%; color: #394a78;'>";

			foreach ($colDef['options'] as $oid)
			{
				$ic = $this->columnsOptions[$oid];
				$icon = $this->app()->ui()->icons()->cssClass($ic['icon']);
				$c .= "<i class='{$icon}' title='".utils::es($ic['title'])."'></i> ";
			}

			$c .= "</span>";
		}

		return $c;
	}

	function tableColumnsCode_Reference($col)
	{
		$colDef = json_decode($col['jsonDef'], TRUE);

		$c = '';
		if ($col['colTypeReferenceId'] !== '' && $col['colTypeReferenceId'] !== '0')
		{
			$table = $this->db()->query('SELECT ndx, [id], [name], dmWikiPage FROM [swdev_dm_tables] WHERE [id] = %s', $col['colTypeReferenceId'])->fetch();
			if ($table)
			{
				$tableUrl = $table['dmWikiPage'];
				$c .= "<i class='fa fa-table'></i> <a href='$tableUrl'>" . utils::es($table['name']) . '</a>';
				$c .= '<br/>' . "<small>" . utils::es($table['id']) . '</small>';
			}
			else
			{
				$c .= "<div class='e10-error'>Invalid table id `".utils::es($col['colTypeReferenceId'])."`</div>";
			}
		}

		if ($col['colTypeId'] === 'enumInt' || $col['colTypeId'] === 'enumString')
		{
			if ($col['colTypeEnumId'] !== '')
			{
				if ($col['colTypeReferenceId'] !== '' && $col['colTypeReferenceId'] !== '0')
					$c .= '<br>';

				$enum = $this->db()->query('SELECT ndx, [id], [name], dmWikiPage FROM [swdev_dm_enums] WHERE [id] = %s', $col['colTypeEnumId'])->fetch();
				if ($enum && $enum['dmWikiPage'])
				{
					$tableUrl = $enum['dmWikiPage'];
					$c .= "<i class='fa fa-th-list'></i> <a href='$tableUrl'>".utils::es($enum['id']).'</a>';
					$c .= '<br/>' . "<small>" . utils::es($enum['name']) . '</small>';
				}
				else
				{
					$c .= "<i class='fa fa-th-list'></i> ".utils::es($col['colTypeEnumId']);
				}
			}
			else
			{
				if ($colDef && isset($colDef['enumValues']))
				{
					foreach ($colDef['enumValues'] as $key => $value)
					{
						$c .= "`" . utils::es($key) . "`:";
						if ($value !== '')
							$c .= " " . utils::es($value);
						$c .= "<br>\n";
					}
				}
			}
		}

		return $c;
	}
}

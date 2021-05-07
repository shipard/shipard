<?php

namespace swdev\dm\libs;
use \Shipard\Utils\TableRenderer;
use \e10\utils;


/**
 * Class WikiSystemPageTerm
 * @package swdev\dm\libs
 */
class WikiSystemPageTerm extends \e10pro\kb\libs\SystemPageEngine
{
	/** @var \swdev\dm\TableTerms */
	var $tableTerms;
	var $ownerText = 0;

	function init()
	{
		$this->ownerText = intval($this->app()->cfgItem ('options.swdevDocumentation.wikiPageTerms', 0));
		$this->tableTerms = $this->app()->table('swdev.dm.terms');

		parent::init();
	}

	public function regenerateAllPages()
	{
		echo "-- generating wiki pages for TERMS...\n";

		if (!$this->ownerText)
			return;

		$q[] = 'SELECT [terms].*';
		array_push($q, ' FROM [swdev_dm_terms] AS [terms]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [terms].docState = %i', 4000);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			echo " - ".$r['fullName'].': '.$r['ndx']."\n";
			$this->regenerateOnePage($r);
		}
	}

	function regenerateOnePage($termRecData)
	{
		/** @var \e10pro\kb\TableTexts $tableTexts */
		$tableTexts = $this->app()->table('e10pro.kb.texts');

		$exist = $this->searchPage('swdev-dm-term', $this->ownerText, 1324, $termRecData['ndx']);
		if ($exist)
		{
			$update['title'] = $termRecData['fullName'];
			$update['subTitle'] = $termRecData['shortName'].' #'.$termRecData['ndx'];
			$update['docState'] = 4000;
			$update['docStateMain'] = 2;

			$wikiPageNdx = $exist['ndx'];
			$this->db()->query('UPDATE [e10pro_kb_texts] SET ', $update, ' WHERE [ndx] = %i', $wikiPageNdx);
		}
		else
		{
			$newPage = $this->createPage('swdev-dm-term', $this->ownerText, 1324, $termRecData['ndx']);
			$newPage['title'] = $termRecData['fullName'];
			$newPage['subTitle'] = $termRecData['shortName'].' #'.$termRecData['ndx'];
			$wikiPageNdx = $tableTexts->dbInsertRec($newPage);
			$tableTexts->docsLog($wikiPageNdx);
		}

		if ($termRecData['dmWikiPage'] == 0)
		{
			$this->db()->query('UPDATE [swdev_dm_terms] SET [dmWikiPage] = %i', $wikiPageNdx, ' WHERE [ndx] = %i', $termRecData['ndx']);
		}

		$wikiPageRecData = $tableTexts->loadItem($wikiPageNdx);
		$tableTexts->checkAfterSave2($wikiPageRecData);
	}

	public function renderPage($pageRecData)
	{
		$termRecData = $this->tableTerms->loadItem($pageRecData['srcRecNdx']);

		$text = $termRecData['text'];
		$text .= $this->tableTermsLinks($termRecData);

		return $text;
	}

	function tableTermsLinks($termRecData)
	{
		$c = '';

		$allLinks = [];
		$this->tableTerms->getSeeAlsoLinks($termRecData,$allLinks, FALSE);

		if (!count($allLinks))
			return $c;

		$c .= "<hr>";

		foreach ($allLinks as $linkType)
		{
			$c .= "<h4>".utils::es($linkType['linkTitle']).'</h4>';
			$c .= "<ul>";
			foreach ($linkType['links'] as $link)
			{
				$url = strval($link['dmWikiPage']);
				$c .= "<li>";
				$c .= "<a href='$url'>".$this->app()->ui()->composeTextLine ($link['title']).'</a>';
				$c .= "</li>";
			}
			$c .= '</ul>';
		}

		return $c;
	}
}

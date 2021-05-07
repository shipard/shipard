<?php

namespace e10pro\kb\libs;
use \e10\Utility;


/**
 * Class SystemPageEngine
 * @package e10pro\kb\libs
 */
class SystemPageEngine extends Utility
{
	function init()
	{
	}

	public function onPageSave(&$pageRecData)
	{
		if ($pageRecData['docState'] === 4000)
			$this->generatePageContent($pageRecData);
	}

	public function generatePageContent(&$pageRecData)
	{
	}

	public function regenerateAllPages()
	{
		/** @var \e10pro\kb\TableTexts $tableTexts */
		$tableTexts = $this->app()->table('e10pro.kb.texts');

		$q[] = 'SELECT * FROM [e10pro_kb_texts]';
		array_push($q, ' WHERE [pageType] = %s', 'kb-user-page');
		array_push($q, ' AND [docState] = %i', 4000);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$recData = $r->toArray();
			$tableTexts->checkAfterSave2($recData);
		}
	}

	function searchPage($pageType, $ownerPageNdx, $srcTableNdx, $srcRecNdx)
	{
		$q[] = 'SELECT * FROM [e10pro_kb_texts]';
		array_push($q, ' WHERE [pageType] = %s', $pageType);
		if ($ownerPageNdx)
			array_push($q, ' AND [ownerText] = %i', $ownerPageNdx);
		array_push($q, ' AND [srcTableNdx] = %i', $srcTableNdx);
		array_push($q, ' AND [srcRecNdx] = %i', $srcRecNdx);

		$r = $this->db()->query($q)->fetch();

		if (!$r)
			return NULL;

		return $r->toArray();
	}

	function createPage($pageType, $ownerPageNdx, $srcTableNdx, $srcRecNdx)
	{
		$newPage = [
			'pageType' => $pageType,
			'ownerText' => $ownerPageNdx,
			'srcTableNdx' => $srcTableNdx, 'srcRecNdx' => $srcRecNdx,
			'srcLanguage' => 102,
			'docState' => 4000, 'docStateMain' => 2
		];

		return $newPage;
	}

	public function renderPage($pageRecData)
	{
		return $pageRecData['text'];
	}
}

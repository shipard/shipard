<?php

namespace e10pro\kb;


/**
 * Class ModuleServices
 * @package e10pro\wkf
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppUpgrade ()
	{
		$this->checkWikies();

		$s [] = ['end' => '2019-12-31', 'sql' => "UPDATE [e10pro_kb_texts] SET [pageType] = 'kb-user-page' WHERE [pageType] = ''"];
		$s [] = ['end' => '2019-12-31', 'sql' => "UPDATE [e10pro_kb_texts] SET [srcLanguage] = 102 WHERE [srcLanguage] = 0"];
		$s [] = ['end' => '2019-12-31', 'sql' => "UPDATE [e10pro_kb_textsRendered] SET [dstLanguage] = 102 WHERE [dstLanguage] = 0"];

		$s [] = ['end' => '2017-09-30', 'sql' => "UPDATE [e10pro_kb_sections] SET [wiki] = 1 WHERE [wiki] = 0"];
		$s [] = ['end' => '2017-09-30', 'sql' => "update e10pro_kb_wikies set docState = 4000, docStateMain = 2 where docState = 0"];

		$this->doSqlScripts ($s);
	}

	function checkWikies ()
	{
		$cntWikies = $this->app->db()->query ('SELECT COUNT(*) AS cnt FROM [e10pro_kb_wikies]')->fetch();
		if (isset($cntWikies['cnt']) && $cntWikies['cnt'])
			return;

		$tableWikies = $this->app->table ('e10pro.kb.wikies');
		$newWiki = [
			'fullName' => 'Firemní Wiki', 'shortName' => 'Wiki', 'title' => '',
			'order' => 10000, 'publicRead' => 0,
			'docState' => 4000, 'docStateMain' => 2
		];

		$newWikiNdx = $tableWikies->dbInsertRec($newWiki);
		$tableWikies->docsLog ($newWikiNdx);

		$this->app->db()->query ('UPDATE [e10pro_kb_sections] SET [wiki] = %i', $newWikiNdx, ' WHERE [wiki] = 0');
	}

	function regenerateWikiPages()
	{
		$allPageTypes = $this->app->cfgItem('e10pro.kb.wiki.pageTypes', NULL);
		if (!$allPageTypes)
			return TRUE;

		foreach ($allPageTypes as $pageTypeCfg)
		{
			if (isset($pageTypeCfg['disabled']) && $pageTypeCfg['disabled'])
				continue;
			echo $pageTypeCfg['name']."\n";
			$classId = $pageTypeCfg['classId'];
			/** @var \e10pro\kb\libs\SystemPageEngine $o */
			$o = $this->app->createObject($classId);
			$o->init();
			$o->regenerateAllPages();
		}

		return TRUE;
	}

	function resetWikiPagesSections()
	{
		$pme = new \e10pro\kb\libs\PageMoveEngine($this->app);
		$pme->resetWikiPagesSections();

	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'regenerate-wiki-pages': return $this->regenerateWikiPages();
			case 'reset-wiki-pages-sections': return $this->resetWikiPagesSections();
		}

		parent::onCliAction($actionId);
	}
}

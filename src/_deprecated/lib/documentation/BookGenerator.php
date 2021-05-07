<?php

namespace lib\Documentation;

require_once __APP_DIR__ . '/e10-modules/e10pro/kb/kb.php';

use \E10\FormReport, \E10\Resizer, \E10\utils;


/**
 * Class BookGenerator
 * @package lib\Documentation
 */
class BookGenerator extends FormReport
{
	var $tableTexts;
	var $tableSections;

	var $srcBookPages = [];
	var $srcBookSections = [];

	var $rootDir;
	var $bookDir;

	var $bookFileId = '';
	var $destSubFolder = 'public';
	var $destDir;

	var $bookFiles = [];

	var $options = NULL;
	var $dictVariant = NULL;

	var $chunkFormats;
	var $bookCoreFileName;

	var $bookPagesNdxs = [];
	var $bookContent = [];

	public function __construct ($app)
	{
		$this->tableTexts = $app->table ('e10pro.kb.texts');
		$this->tableSections = $app->table ('e10pro.kb.sections');

		$this->bookFileId = 'aa';
		$table = $app->table ('e10pro.kb.texts');
		parent::__construct ($table, ['ndx' => 0]);

		$this->chunkFormats = [
			'xhtml' => ['subdir' => 'xhtml', 'fileExt' => 'xhtml', 'imagesSubdir' => 'images'],
			'pdf' 	=> ['subdir' => 'pdf', 'fileExt' => 'html', 'imagesSubdir' => 'images', 'single' => 1],
			'epub' 	=> ['subdir' => 'epub', 'fileExt' => 'xhtml', 'imagesSubdir' => 'images'],
		];
	}

	function addSrcBookSection ($sectionNdx)
	{
		if (!isset($this->recData['title']))
		{
			$sectionRecData = $this->tableSections->loadItem ($sectionNdx);
			if ($sectionRecData)
			{
				$this->recData['title'] = $sectionRecData['title'];
			}
		}
		$this->srcBookSections[] = $sectionNdx;
	}

	function addSrcBookPage ($sectionNdx)
	{
		$this->srcBookPages[] = $sectionNdx;
	}

	function init ()
	{
		$this->rootDir = 'tmp/books-'.time().'-'.mt_rand(1, 99999999);
		mkdir($this->rootDir, 0755, TRUE);
		$this->destDir = __APP_DIR__.'/documentation/'.$this->destSubFolder.'/';
	}

	public function setOptions ($fileName, $dictVariant = NULL, $destFubFolder = NULL)
	{
		$this->options = utils::loadCfgFile($fileName);
		$this->dictVariant = $dictVariant;

		if (isset ($this->options['dict']))
		{
			$this->setInfo('dict', $this->options['dict']);
			$this->lang = $dictVariant;
		}
		if ($destFubFolder)
			$this->destSubFolder = $destFubFolder;
	}

	public function generateBook ()
	{
		$this->init();
		$this->loadAllPages();

		$this->bookDir = $this->rootDir.'/'.'123';
		foreach ($this->chunkFormats as &$chf)
		{
			$chf['path'] = $this->bookDir.'/'.$chf['subdir'];
			mkdir($chf['path'], 0755, TRUE);

			$chf['imagesPath'] = $this->bookDir.'/'.$chf['subdir'].'/'.$chf['imagesSubdir'];
			mkdir($chf['imagesPath'], 0755, TRUE);
		}
		mkdir($this->bookDir.'/download', 0755, TRUE);

		$this->bookCoreFileName = ($this->recData['id'] != '') ? $this->recData['id'] : 'book-'.$this->recData['ndx'];

		$this->generateBookSingle('pdf');
/*
		$engine = new kbTextsEngine($this->table->app(), kbTextsEngine::pmBigText);
		$engine->init();
		$engine->bookNdx = $this->recData ['ndx'];

		$engine->setText($engine->bookNdx);
		$engine->createBookContent();

		$this->createPageText ($engine, $this->recData ['ndx']);
		$this->generateBookPage ($engine, $this->recData ['ndx']);

		foreach ($engine->bookContent as $pageNdx => $pageRec)
		{
			$this->createPageText ($engine, $pageNdx);
			$this->generateBookPage ($engine, $pageNdx);
		}

		foreach ($this->chunkFormats as $chfId => $chfX)
		{
			$this->generateBookContent($engine, $chfId);
		}
*/
		if (is_dir($this->destDir.$this->bookFileId))
			rename ($this->destDir.$this->bookFileId, $this->destDir.$this->bookFileId.'-OLD');

		if (!is_dir($this->destDir.$this->bookFileId))
			mkdir($this->destDir.$this->bookFileId, 0755, TRUE);

		rename ($this->bookDir, $this->destDir.$this->bookFileId);
		exec ('rm -rf '.$this->destDir.$this->bookFileId.'-OLD');

		if (is_dir($this->rootDir))
			exec ('rm -rf '.$this->rootDir);
	}

	public function generateBookSingle($chfId)
	{
		$chf = $this->chunkFormats[$chfId];

		$texy = new \E10\Web\E10Texy($this->app, $this->page);
		//$this->recData['bookPerex'] = $texy->process($this->recData['perex']);
		$this->recData['bookContent'] = $this->createContent ($this->bookContent);

		$bookText = '';
		foreach ($this->bookPagesNdxs as $pageNdx)
		{
			$pageRecData = $this->tableTexts->loadItem ($pageNdx);

			$srcPageText = '';
			$srcPageText .= '####'.$pageRecData['title'].' .[pageTitle #bpt'.$pageNdx.']'."\n";
			//$srcPageText .= "<h2 class='pageTitle' id='bpt{$pageNdx}' name='bpt{$pageNdx}'>".utils::es($pageRecData['title']).'</h2>'."\n";
			$srcPageText .= $pageRecData['text'];

			$bookText .= $texy->process($srcPageText);
		}

		$this->recData['bookTextSource'] = $bookText;
		$this->page['text'] = $bookText;

		$pageText = $this->renderTemplate ('e10pro.kb.'.$chfId);
		$this->createImages ($chfId);
		$fileName = $chf['path'].'/book.'.$chf['fileExt'];
		file_put_contents($fileName, $pageText);

		if ($chfId === 'pdf')
		{
			$cfn = $this->bookCoreFileName;
			$cmd = 'phantomjs '.__APP_DIR__ . '/e10-modules/e10/server/utils/createpdf.js '.$chf['path'].'/book.html'.' '.
				$this->bookDir.'/download/'.$cfn.'.pdf'.' '.'A4'.' '.'portrait'.' '.'1cm';

			exec ($cmd);
			exec ('rm -rf '.$chf['path'].'/images');
			exec ('rm -rf '.$chf['path'].'/images');
			unlink ($chf['path'].'/book.html');
			//rename ($chf['path'].'/'.$cfn.'.pdf', $this->bookDir.'/'.$cfn.'.pdf');
			$fs = round (filesize($this->bookDir.'/download/'.$cfn.'.pdf') / (1024*1024), 1).' MB';
			$this->chunkFormats[$chfId]['download'] = [
				'url' => $this->recData ['ndx'].'/download/'.$cfn.'.pdf',
				'format' => 'pdf', 'icon' => 'fa fa-file-pdf-o',
				'baseFileName' => $cfn.'.pdf', 'fileSize' => $fs];
			$this->bookFiles [] = [
				'url' => 'documentation'.'/'.$this->destSubFolder.'/'.$this->bookFileId.'/download/'.$cfn.'.pdf',
			];
		}
	}

	public function createPageText ($engine, $pageNdx)
	{
		$engine->resetPage ();
		if (!$engine->setText($pageNdx))
		{
			error_log ("Page $pageNdx not found!");
			return;
		}
		$engine->createPageText();
	}

	protected function generateBookContent ($engine, $type)
	{
		switch ($type)
		{
			case 'epub': return $this->generateBookContentEPUB ($engine, $type);
			case 'xhtml': return $this->generateBookContentXHTML ($engine, $type);
		}
	}

	protected function generateBookContentEPUB ($engine, $type)
	{

	}

	protected function generateBookContentXHTML ($engine, $type)
	{
		$chf = $this->chunkFormats[$type];

		reset ($engine->bookContent);
		$firstPageNdx = key($engine->bookContent);

		$content = [
			$this->bookNdx => ['title' => $this->recData['title'],
				'url' => strval ($this->bookNdx), 'treeLevel' => 1, 'ownerText' => 0,
				'pageNext' => ['title' => $engine->bookContent[$firstPageNdx]['title'], 'ndx' => $engine->bookContent[$firstPageNdx]['ndx']]]
		];

		foreach ($engine->bookContent as $pageNdx => $pageRec)
		{
			$ci = $pageRec;
			$ci['url'] = $this->bookNdx.'.'.$pageNdx;
			$content[$pageNdx] = $ci;
		}

		$book = ['title' => $this->recData['title'], 'ndx' => $this->recData['ndx'],
			'coreFileName' => $this->bookCoreFileName];


		foreach ($this->chunkFormats as $dff)
		{
			if (isset ($dff['download']))
				$book['downloads'][] = $dff['download'];
		}

		$book ['content'] = $content;
		$content = json_encode ($book, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

		$fileName = $chf['path'].'/'.'book.json';
		file_put_contents($fileName, $content);
	}

	protected function generateBookPage ($engine, $pageNdx)
	{
		foreach ($this->chunkFormats as $chfId => $chf)
		{
			if (isset ($chf['single']))
				continue;

			$this->reportId = 'e10pro.kb.'.$chfId;
			$this->reportTemplate = 'e10pro.kb.'.$chfId;

			$this->recData['bookTextSource'] = $engine->page ['kbText'];
			//$this->recData['bookContent'] = $engine->oneBigTextTOCHtml;

			$pageText = $this->renderTemplate ('e10pro.kb.'.$chfId);
			$this->createImages ($chfId);

			$fileName = $chf['path'].'/'.$pageNdx.'.'.$chf['fileExt'];
			file_put_contents($fileName, $pageText);
		}
	}

	protected function createImages ($type)
	{
		$chf = $this->chunkFormats[$type];

		foreach ($this->registeredImages as $i)
		{
			if (substr ($i['src'], 0, 6) === '/imgs/')
			{
				$resizer = new Resizer ($this->app);
				$resizer->createSrcFileName ($i['src']);
				$resizer->resize();

				copy ($resizer->cacheFullFileName, $chf['imagesPath'].'/'.$i['dest']);
				unset ($resizer);
			}
			else
				copy (__APP_DIR__.'/'.$i['src'], $chf['imagesPath'].'/'.$i['dest']);
		}
		unset ($this->registeredImages);
		$this->registeredImages = [];
	}

	function loadAllPages ()
	{
		foreach ($this->srcBookSections as $sectionNdx)
		{
			$q = [];
			array_push($q, ' SELECT texts.* FROM [e10pro_kb_texts] AS texts');
			array_push($q, ' WHERE texts.section = %i', $sectionNdx);
			array_push($q, ' AND texts.ownerText = %i', 0);
			array_push($q, ' AND texts.docStateMain < %i', 4);
			array_push($q, ' ORDER BY texts.[order], texts.[title]');
			$rows = $this->db()->query($q);
			foreach ($rows as $r)
			{
				$this->bookPagesNdxs[] = $r['ndx'];

				$contentItem = ['title' => $r['title'], 'ndx' => $r['ndx'], 'content' => []];

				$this->loadInsidePages($r['ndx'], $contentItem['content']);
				$this->bookContent[] = $contentItem;
			}
		}

		foreach ($this->srcBookPages as $pageNdx)
		{
			$this->bookPagesNdxs[] = $pageNdx;
			$this->loadInsidePages($pageNdx, $this->bookContent);
		}
	}

	function loadInsidePages ($pageNdx, &$content)
	{
		$q [] = 'SELECT * FROM [e10pro_kb_texts] WHERE 1 ';
		array_push ($q, ' AND [ownerText] = %i', $pageNdx);
		array_push ($q, ' AND docState != 9800');
		array_push ($q, ' ORDER BY [order], [title]');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->bookPagesNdxs[] = $r['ndx'];

			$contentItem = ['title' => $r['title'], 'ndx' => $r['ndx'], 'content' => []];
			$this->loadInsidePages ($r['ndx'], $contentItem['content']);
			$content[] = $contentItem;
		}
	}

	function createContent ($content, $level = 0)
	{
		$c = '';

		if (!count($content))
			return $c;

		$c .= str_repeat("\t", $level)."<ul>\n";
		foreach ($content as $p)
		{
			$c .= str_repeat("\t", $level+1)."<li>\n";
			$c .= str_repeat("\t", $level+2)."<a href='#bpt{$p['ndx']}'>".utils::es($p['title'])."</a>\n";
			$c .= $this->createContent($p['content'], $level + 2);
			$c .= str_repeat("\t", $level+1)."</li>\n";
		}
		$c .= str_repeat("\t", $level)."</ul>\n";

		return $c;
	}
}

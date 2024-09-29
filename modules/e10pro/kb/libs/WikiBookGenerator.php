<?php

namespace e10pro\kb\libs;
use \Shipard\base\Utility;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Json;
use \e10pro\kb\WikiEngine;


/**
 * class WikiBookGenerator
 */
class WikiBookGenerator extends Utility
{
  var $srcWikiNdx = 0;

  var $srcSectionNdx = 0;
  var $srcSectionRecData = NULL;

	var $srcBookSections = [];
  var $srcBookContent = [];
  var $srcBookPages = [];

  var $pdfContentCode = '';
  var $bookText = '';
  var $bookTitle = '';
	var $bookVersionId = '';

  var $bookPdfURL = '';
  var $bookPdfFileName = '';

  var $rootDir = '';

  CONST btPdf = 1, btEPUB = 2;

  var $bookTypes = [
    self::btPdf => [
      'id' => 'pdf',
    ],
    self::btEPUB => [
      'id' => 'epub',
    ]
  ];


  function init ()
	{
		$this->rootDir = 'tmp/books-'.time().'-'.mt_rand(1, 99999999);
		mkdir($this->rootDir, 0755, TRUE);

    $now = new \DateTime();
    $this->bookVersionId = $now->format('ymd-Hi');
	}

  public function setSource($wikiNdx, $sectionNdx)
  {
    $this->srcWikiNdx = $wikiNdx;
    $this->srcSectionNdx = $sectionNdx;

    $this->srcSectionRecData = $this->app()->loadItem($sectionNdx, 'e10pro.kb.sections');
    $this->bookTitle = $this->srcSectionRecData['bookTitle'];
    if ($this->bookTitle === '')
      $this->bookTitle = $this->srcSectionRecData['title'];
    $this->srcBookSections[] = $sectionNdx;
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
				$this->srcBookPages[] = $r['ndx'];

				$contentItem = ['title' => $r['title'], 'ndx' => $r['ndx'], 'content' => []];

				$this->loadInsidePages($r['ndx'], $contentItem['content']);
				$this->srcBookContent[] = $contentItem;
			}
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
			$this->srcBookPages[] = $r['ndx'];

			$contentItem = ['title' => $r['title'], 'ndx' => $r['ndx'], 'content' => []];
			$this->loadInsidePages ($r['ndx'], $contentItem['content']);
			$content[] = $contentItem;
		}
	}

  public function generateBook($bookType)
  {
    $bookDir = $this->rootDir.'/'.$this->bookTypes[$bookType]['id'];
		mkdir($bookDir, 0755, TRUE);

    $imagesDir = $bookDir.'/'.'images';
		mkdir($imagesDir, 0755, TRUE);

    $bookBaseURL = 'https://'.$this->app()->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid') . '/'. $bookDir.'/';
    //data.bookBaseURL

    if ($bookType === self::btPdf)
      $this->bookText = '';
    foreach ($this->srcBookPages as $pageNdx)
    {
      $page = [];

      $pageId = strval($pageNdx);


      $wikiEngine = new WikiEngine ($this->app());
      $wikiEngine->urlBegin = $this->app()->urlRoot;//.(($isForcedUrl) ? $forcedUrl : $url).'/';
      $wikiEngine->wikiNdx = $this->srcWikiNdx;

			$template = new \Shipard\Report\TemplateMustache ($this->app());
      $template->templateRoot = __SHPD_MODULES_DIR__.'e10pro/kb/reports/pdf/';
      $template->data['bookBaseURL'] = $bookBaseURL;

      $wikiEngine->setPageId($pageId, $template);
      $wikiEngine->thisUserId = 1;
      $wikiEngine->run();

      if (count($template->registeredImages))
      {
        $this->createImages($bookType, $template->registeredImages, $imagesDir);
      }

      if ($bookType === self::btPdf)
        $this->bookText .= $wikiEngine->page['code'];

      file_put_contents($bookDir.'/'.$pageNdx.'.html', $wikiEngine->page['code']);
    }

    file_put_contents($bookDir.'/'.'book.html', $this->bookText);
    file_put_contents($bookDir.'/'.'book.json', Json::lint($this->srcBookContent));
  }

	protected function createImages ($bookType, $registeredImages, $imagesDir)
	{
		foreach ($registeredImages as $i)
		{
			if (substr ($i['src'], 0, 6) === '/imgs/')
			{
				$resizer = new \Shipard\Base\ImageResizer ($this->app);
				$resizer->createSrcFileName ($i['src']);
				$resizer->resize();

				copy ($resizer->cacheFullFileName, $imagesDir.'/'.$i['dest']);
				unset ($resizer);
			}
			else
				copy (__APP_DIR__.'/'.$i['src'], $imagesDir.'/'.$i['dest']);
		}
	}

  protected function createPdf()
  {
    $this->bookPdfFileName = Utils::safeFileName($this->bookTitle).'-'.$this->bookVersionId.'.pdf';

    $this->pdfContentCode = $this->contentCode($this->srcBookContent, 0);
		$report = new \e10pro\kb\libs\WikiBookReport($this->app, $this);
    $report->init();
    $report->renderReport ();
    $report->createReport ();

    $rfn = substr($report->reportSrcFileNameRelative, 0, -(strlen($report->srcFileExtension) + 1)) . '.pdf';
    $this->bookPdfURL = 'https://'.$this->app()->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid') . '/'. $rfn;

    if ($this->app()->debug)
      echo "book url: ".$this->bookPdfURL."\n";
  }

  protected function contentCode($content, $level)
  {
    $c = '';

    $c .= "<ul>\n";
    foreach ($content as $chapter)
    {
      $c .= "<li>\n";
      $c .= "<span class='content-item content-item-$level'>";
      $c .= "<a href='#page-{$chapter['ndx']}'>".Utils::es($chapter['title'])."</a>\n";
      $c .= "</span>\n";
      if (isset($chapter['content']) && count($chapter['content']))
      {
        $c .= $this->contentCode($chapter['content'], $level + 1);
      }
      $c .= "</li>\n";
    }
    $c .= "</ul>\n";

    return $c;
  }

  public function run()
  {
    $this->init();
    $this->loadAllPages();

    $this->generateBook(self::btPdf);
    $this->createPdf();

    if (!$this->app()->debug)
      exec ('rm -rf '.$this->rootDir);
  }
}

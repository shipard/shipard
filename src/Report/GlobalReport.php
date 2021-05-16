<?php

namespace Shipard\Report;
use \Shipard\Utils\Utils;
use \Shipard\UI\Core\ContentRenderer;
use \Shipard\UI\Core\UIUtils;


class GlobalReport extends Report
{
	var $subReportId = '';
	protected $params;
	protected $reportParams;
	var $detailMode = FALSE;

	/** @var \lib\tests\Test */
	var $testEngine = NULL;

	public function __construct ($app)
	{
		parent::__construct($app);
		$this->createParamsObject ();
		$this->subReportId = $this->app->testGetParam('subReportId');
	}

	protected function createParamsObject ()
	{
		$this->params = new \E10\Params ($this->app);
	}

	function setParamsValues ($externalParamsValues)
	{
		$this->params->externalParamsValues = $externalParamsValues;
	}

	function init ()
	{
		parent::init();
		$this->reportTemplate = 'reports.default.globalReport.default';
		$this->reportParams = $this->params->detectValues();
	}

	public function addContent ($contentPart, $partId = FALSE)
	{
		if ($contentPart === FALSE)
			return;

		if (isset ($contentPart[0]) && is_array($contentPart[0]))
		{
			forEach ($contentPart as $oneItem)
				$this->content[] = $oneItem;
			return;
		}

		if ($partId !== FALSE)
			$this->content[$partId] = $contentPart;
		else
			$this->content[] = $contentPart;
	}

	public function createContent ()
	{
	}

	public function createTabsCode ()
	{
		$c = "";

		$details = $this->subReportsList ();
		if (count($details))
		{
			$firstClass = " class='active'";
			$c .= "<ul class='df2-detail-menu report' id='mainReportWidgetTabs'>\n";
			foreach ($details as $detail)
			{
				$c .= "<li data-subreport='{$detail['id']}'$firstClass>";
				if (isset($detail['icontxt']))
				{
					$c .= "<div class=''>".$detail['icontxt']."</div>";
				}
				else
				{
					$icon = $this->app()->ui()->icons()->cssClass($detail['icon']);
					$c .= "<div class='$icon'></div>";
				}
				$c .= \E10\es ($detail['title']);
				$c .= '</li>';
				$firstClass = "";
			}
			$c .= "</ul>\n";
		}

		return $c;
	}

	public function createDetail ()
	{
		$this->format = 'widget';
		$this->detailMode = TRUE;
		$this->saveAs = FALSE;
		$this->init();
		$this->renderReport();
		$this->createReport();
	}

	public function createPdf ()
	{
		$this->format = 'pdf';
		$this->init();
		$this->saveAs = FALSE;
		$this->renderReport();
		$this->createReport();
	}

	public function addParam ($paramType, $paramId = FALSE, $options = NULL)
	{
		$this->params->addParam ($paramType, $paramId, $options);
	}

	public function setParams ($params) {$this->params->setParams ($params);}

	public function subReportsList ()
	{
		return array();
	}

	public function createReportContent ()
	{
		$c = $this->createReportContentTemplate ();
		if ($c !== '')
			return $c;

		$cr = new ContentRenderer ($this->app);
		$cr->setReport($this);
		$code = $cr->createCode();

		if (!$this->app->printMode && !$this->app->mobileMode)
		{
			$code .= "<script>
					$('div.e10-reportContent table.main').floatThead({
						scrollContainer: function(table){
							return $('#mainBrowserContent div.e10-reportContent');
						},
						useAbsolutePositioning: false,
						zIndex: 101,".
						(($this->mobile) ? "scrollingTop: $('#e10-page-header').height()" : '').
						"
					});
				</script>
				";
		}

		return $code;
	}

	public function saveReportAs ()
	{
		if ($this->format === 'xlsx')
			$this->saveReportAsExcel ();
	}

	public function saveReportAsExcel ()
	{
		$excelEngine = new \lib\E10Excel ($this->app);
		$spreadsheet = $excelEngine->create();

		$cr = new \lib\E10Excel\ContentRenderer($this->app);

		$hdr = $this->createReportContentHeader(NULL);
		if ($hdr !== '')
			$cr->setReportHeader($this->info);

		$cr->render($excelEngine, $spreadsheet, 0, $this->content);

		$excelEngine->setAutoSize($spreadsheet);

		$baseFileName = $excelEngine->save ($spreadsheet);
		$this->fullFileName = __APP_DIR__.'/tmp/'.$baseFileName;
		$this->saveFileName = $this->saveAsFileName ();
	}

	function saveAsFileName ()
	{
		$fileName = 'report';
		if (isset($this->info['title']))
			$fileName = $this->info['title'];
		return $fileName.'.'.$this->format;
	}

	public function createReportContentHeader ($contentPart)
	{
		return UIUtils::createReportContentHeader ($this->app, $this->info);
	}

	public function createReportContentNotes ()
	{
		if (!isset ($this->info['note']))
			return '';

		if (count ($this->info['note']) === 1)
		{
			$n = array_pop($this->info['note']);
			return "<p class='e10-reportNotes'>".utils::es($n).'</p>';
		}

		$c = "<ol class='e10-reportNotes'>";
		foreach ($this->info['note'] as $n)
			$c .= '<li>'.utils::es($n).'</li>';
		$c .= '</ol>';
		return $c;
	}

	public function createReportContentTemplate ()
	{
		$c = '';

		$t = new TemplateMustache ($this->app);
		if ($t->loadTemplate($this->reportId) !== FALSE)
		{
			$t->setData ($this);
			$c = $t->renderTemplate ();
		}
		return $c;
	}

	public function renderReport ()
	{
		if ($this->format === 'pdf')
			$this->app()->printMode = TRUE;

		$this->info['reportFormat'][$this->format] = TRUE;
		$this->createContent();

		switch ($this->format)
		{
			case	'pdf':
							$this->data ['text'] = $this->createReportContent ();
							$this->objectData ['mainCode'] = $this->renderTemplate ($this->reportTemplate);
							$this->setSaveFileName ($this->saveFileName() . '.pdf', 'application/pdf');
							break;
			case	'widget':
							{
							/*	if (0)
									$this->objectData ['dataContent'] = $this->content;
								else*/
									$this->objectData ['mainCode'] = $this->createReportContent ();
							}
							break;
			default:
							$this->saveAs = TRUE;
							$this->setSaveFileName ($this->saveFileName() . '.'.$this->format, mime_content_type($this->fullFileName));
							break;
		}

		if ($this->format === 'pdf')
			$this->app->printMode = FALSE;
	}

	public function createToolbar ()
	{
		$printButton = ['text' => 'Tisk', 'icon' => 'icon-print', 'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print'];
		$this->createToolbarSaveAs ($printButton);
		$buttons[] = $printButton;

		if (isset ($this->objectData['attachments']))
		{
			foreach ($this->objectData['attachments'] as $a)
			{
				$b = [
					'text' => $a['title'],
					'icon' => 'icon-cloud-download', 'type' => 'link', 'action' => 'download', 'href' => $this->app->dsRoot.'/'.$a['relFileName']
				];

				$b['downloadFileName'] = $a['downloadFileName'];

				$buttons[] = $b;
			}
		}
		return $buttons;
	}

	public function createToolbarCode ()
	{
		$c = '';

		forEach ($this->params->getParams() as $paramId => $paramContent)
		{
			if (isset ($paramContent['options']['place']))
				continue;
			$c .= $this->params->createParamCode ($paramId);
		}

		$c .= "<span style='padding-left: 3em;'>&nbsp;</span> ";

		$buttons = $this->createToolbar ();
		forEach ($buttons as $btn)
		{
			$c .= ' '.$this->app()->ui()->actionCode($btn);
		}

		return $c;
	}

	public function createToolbarSaveAs (&$printButton)
	{
		if (1)
			$printButton['dropdownMenu'][] = ['text' => 'Uložit jako Microsoft Excel', 'icon' => 'icon-file-excel-o', 'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print', 'data-format' => 'xlsx'];
	}

	public function createPanelCode ()
	{
		$c = '';

		forEach ($this->params->getParams() as $paramId => $paramContent)
		{
			if (!isset ($paramContent['options']['place']))
				continue;
			$c .= $this->params->createParamCode ($paramId).'<br/>';
		}
		return $c;
	}


	protected function saveFileName ()
	{
		if (isset ($this->info['saveFileName']))
			return utils::safeChars($this->info['saveFileName'], FALSE);

		return 'report';
	}

	public function qryPanelAddCheckBoxes ($enum, $queryId, $queryTitle, $defaultChecked = NULL)
	{
		if ($enum !== FALSE && count($enum) !== 0)
		{
			$chbxs = [];
			forEach ($enum as $id => $name)
			{
				if (is_array($name))
					$chbxs[$id] = $name;
				else
					$chbxs[$id] = ['title' => $name, 'id' => $id];
			}
			$paramOptions = ['items' => $chbxs, 'place' => 'panel', 'title' => $queryTitle];
			if ($defaultChecked)
				$paramOptions['defaultChecked'] = $defaultChecked;
			$this->addParam ('checkboxes', 'query.'.$queryId, $paramOptions);
		}
	}

	public function queryValues ()
	{
		$qv = [];
		forEach ($_GET as $qryId => $qryValue)
		{
			$parts = explode ('_', $qryId);
			if ($parts[0] === 'query')
			{
				if (isset($parts[3]))
					$qv[$parts[1]][$parts[2]][$parts[3]] = $qryValue;
				else
					$qv[$parts[1]][$parts[2]] = $qryValue;
			}
		}
		return $qv;
	}

	public function setTestCycle ($cycle, $testEngine)
	{
		$this->testEngine = $testEngine;
	}

	public function testTitle ()
	{
		return ['text' => '...'];
	}
}


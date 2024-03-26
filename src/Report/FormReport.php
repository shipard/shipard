<?php

namespace Shipard\Report;
use \Shipard\Utils\Utils;


class FormReport extends Report
{
	protected $table;
	public $recData;

	public function __construct ($table, $recData)
	{
		parent::__construct ($table->app ());
		$this->table = $table;
		$this->recData = $recData;
		$this->recData ['print'] = $this->getPrintValues ($this->table, $recData);
		$this->loadData ();

		if ($this->app->testGetParam ('saveas') !== '')
			$this->saveAs = $this->app->testGetParam ('saveas');
	}

	public function init ()
	{
		parent::init();
	}

	public function checkDocumentInfo (&$documentInfo) {}

	public function getPrintValues ($table, $item)
	{
		return $table->getPrintValues ($item);
	}

	public function renderReport ()
	{
		$this->loadData2();

		if ($this->saveAs === FALSE)
		{
			$fileName = Utils::safeChars($this->createReportPart('fileName'), TRUE);
			if ($fileName != '')
				$this->setSaveFileName($fileName.'.pdf');
		}
		elseif ($this->saveAs === 'json')
		{
			$fileName = Utils::safeChars($this->createReportPart('fileName'), TRUE);

			$this->setSaveFileName($fileName.'.txt');
			$this->mimeType = 'text/plain';
		}
		elseif ($this->saveAs === 'html')
		{
			$fileName = Utils::safeChars($this->createReportPart('fileName'), TRUE);

			$this->setSaveFileName($fileName.'.html');
			$this->mimeType = 'text/html';
		}

		if ($this->reportMode == FormReport::rmPOS || $this->reportMode == FormReport::rmLabels)
		{
			if ($this->rasterPrint)
			{
				$this->srcFileExtension = 'html';
				$this->mimeType = 'image/png';
			}
			else
			{
				$this->srcFileExtension = 'rawprint';
				$this->mimeType = 'application/x-octet-stream';
			}
			$this->objectData ['mainCode'] = $this->renderTemplate ($this->reportTemplate);
		}
		else
		{
			parent::renderReport();
		}
	}

	public function saveReportAs ()
	{
		if ($this->saveAs === 'json')
		{
			$this->fullFileName = __APP_DIR__ . "/tmp/r-" . time() . '-' . mt_rand () . '.txt';
			$this->mimeType = 'text/plain';
			if ($this->app()->hasRole('admin'))
			{
				$t = new TemplateMustache ($this->app);
				$t->loadTemplate($this->reportId);
				$t->setData ($this);
				file_put_contents($this->fullFileName, json::lint(['data' => $t->data]));
			}
			else
				file_put_contents($this->fullFileName, 'forbidden');
		}
		elseif ($this->saveAs === 'html')
		{
			$this->fullFileName = __APP_DIR__ . "/tmp/r-" . time() . '-' . mt_rand () . '.html';
			$this->mimeType = 'text/html';
			if ($this->app()->hasRole('admin'))
				file_put_contents($this->fullFileName, $this->objectData ['mainCode']);
			else
				file_put_contents($this->fullFileName, 'forbidden');
		}
	}

	protected function createReportHeaderFooter()
	{
		$this->pageHeader = $this->renderTemplate ($this->reportTemplate, 'pageHeader');
		$this->pageFooter = $this->renderTemplate ($this->reportTemplate, 'pageFooter');

		if ($this->pageHeader !== '' && $this->pageFooter === '')
			$this->pageFooter = ' ';
		if ($this->pageFooter !== '' && $this->pageHeader === '')
			$this->pageHeader = ' ';
	}

	public function addMessageAttachments(\Shipard\Report\MailMessage $msg)
	{
	}

	public function loadData ()
	{
		parent::loadData();
		$this->data['mainBCId'] = $this->table->itemMainBCId($this->recData);

		$this->data['footer']['logoPoweredBy'] = '<a href="https://shipard.cz/"><img alt="shipard.cz" class="logo" src="data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHg9IjAiIHk9IjAiIHdpZHRoPSI0MzQuMyIgaGVpZ2h0PSIxMTguNSIgdmlld0JveD0iMCwgMCwgNDM0LjMsIDExOC41Ij4KICA8ZyBpZD0iQmFja2dyb3VuZCI+CiAgICA8cmVjdCB4PSIwIiB5PSIwIiB3aWR0aD0iNDM0LjMiIGhlaWdodD0iMTE4LjUiIGZpbGw9IiNGRkZGRkYiIGZpbGwtb3BhY2l0eT0iMCIvPgogIDwvZz4KICA8ZyBpZD0iTGF5ZXJfMSI+CiAgICA8cGF0aCBkPSJNMS43LDg5LjEgQzAuOSw4OC41IDAuMSw4NyAwLjksODUuNiBMNS4xLDc3LjEgQzUuOCw3NS41IDcuNCw3NSA4LjksNzYgQzExLjksNzcuOCAxNi41LDgwLjIgMjMuMyw4MC4yIEMyOC4yLDgwLjIgMzAuNSw3OC40IDMwLjUsNzUuNSBDMzAuNSw3Mi41IDI2LjgsNjkuOSAxOC4yLDY2LjUgQzUuNiw2MS43IDAsNTUuOCAwLDQ1LjkgQzAsMzYgNy41LDI2LjggMjMuNCwyNi44IEMzMywyNi44IDM5LjIsMjkuMyA0Mi43LDMxLjYgQzQ0LjEsMzIuNyA0NSwzNC40IDQ0LjEsMzYuMSBMNDAuNCw0My42IEMzOS40LDQ1LjIgMzcuNyw0NS40IDM2LjMsNDQuOSBDMzMuMiw0My41IDI4LjcsNDEuNiAyMy4zLDQxLjYgQzE4LjksNDEuNiAxNy4xLDQzLjcgMTcuMSw0NS44IEMxNy4xLDQ4LjggMjAuNCw1MC4zIDI2LjQsNTIuOSBDMzksNTggNDgsNjIuNSA0OCw3NS41IEM0OCw4NS44IDM5LjIsOTUgMjIuOSw5NSBDMTIuMSw5NS4xIDUuNCw5MS43IDEuNyw4OS4xIHoiIGZpbGw9IiMwMDUwOEEiLz4KICAgIDxwYXRoIGQ9Ik02MC43LDYuNCBDNjAuNyw0LjggNjIuMywzLjMgNjMuOCwzLjMgTDc1LjIsMy4zIEM3Ni45LDMuMyA3OC4zLDQuOSA3OC4zLDYuNCBMNzguMywzNS42IEM4MS42LDMyLjggODcuMiwzMC4xIDk1LDMwLjEgQzExNS44LDMwLjEgMTIxLjQsNDQuNSAxMjEuNCw2MC45IEwxMjEuNCw5My43IEMxMjEuNCw5NS40IDExOS44LDk2LjggMTE4LjMsOTYuOCBMMTA2LjksOTYuOCBDMTA1LjIsOTYuOCAxMDMuOCw5NS40IDEwMy44LDkzLjcgTDEwMy44LDYwLjggQzEwMy44LDUxLjggOTkuNyw0Ni4zIDkxLjksNDYuMyBDODQuMyw0Ni4zIDgwLDUxLjEgNzguMyw1Ny43IEw3OC4zLDkzLjcgQzc4LjMsOTUuNSA3Ny41LDk2LjggNzQuOSw5Ni44IEw2My43LDk2LjggQzYyLjEsOTYuOCA2MC42LDk1LjQgNjAuNiw5My43IEw2MC42LDYuNCB6IiBmaWxsPSIjMDA1MDhBIi8+CiAgICA8cGF0aCBkPSJNMjExLjgsOTUuMSBDMjA1LjMsOTUuMSAxOTkuMSw5My41IDE5Ni4xLDkyLjQgTDE5Ni4xLDExNS40IEMxOTYuMSwxMTcgMTk0LjUsMTE4LjUgMTkzLDExOC41IEwxODEuNiwxMTguNSBDMTc5LjksMTE4LjUgMTc4LjUsMTE2LjkgMTc4LjUsMTE1LjQgTDE3OC41LDMxLjQgQzE3OC41LDI5LjcgMTc5LjksMjguMyAxODEuNiwyOC4zIEwxODYuOCwyOC4zIEMxODguNCwyOC4zIDE4OS4zLDI5LjYgMTg5LjksMzEuNCBMMTkxLjYsMzYuNiBDMTkxLjYsMzYuNiAxOTkuMSwyNi45IDIxMi45LDI2LjkgQzIzMC43LDI2LjkgMjQ0LjEsNDEuMyAyNDQuMSw2MS4xIEMyNDQuMSw4MC4xIDIzMS4zLDk1LjEgMjExLjgsOTUuMSB6IE0xOTYuMSw3NS4zIEMxOTYuMSw3NS4zIDIwMS41LDc5IDIwOS43LDc5IEMyMjAuNCw3OSAyMjUuOSw3MC4yIDIyNS45LDYxLjEgQzIyNS45LDUxLjkgMjIwLjcsNDMgMjEwLjUsNDMgQzIwMiw0MyAxOTcuNSw0OC40IDE5Ni4xLDUyIEwxOTYuMSw3NS4zIHoiIGZpbGw9IiMwMDUwOEEiLz4KICAgIDxwYXRoIGQ9Ik0yNzguNyw1Mi4zIEMyODUuMyw1Mi4zIDI5MS4zLDU0LjEgMjkxLjMsNTQuMSBDMjkxLjYsNDUuOCAyODkuMiw0MS44IDI4Mi4zLDQxLjggQzI3Ni40LDQxLjggMjY3LjgsNDMuMSAyNjMuOSw0NC4yIEMyNjEuOCw0NC45IDI2MC41LDQzLjQgMjYwLjIsNDEuNCBMMjU4LjgsMzQuMSBDMjU4LjIsMzEuNyAyNTkuNSwzMC42IDI2MC45LDMwLjEgQzI2Mi4zLDI5LjUgMjcyLjYsMjYuOSAyODMuMiwyNi45IEMzMDMuNSwyNi45IDMwNy44LDM3LjUgMzA3LjgsNTUuMyBMMzA3LjgsOTAuNSBDMzA3LjgsOTIuMiAzMDYuNCw5My42IDMwNC43LDkzLjYgTDI5OS45LDkzLjYgQzI5OC44LDkzLjYgMjk3LjksOTMuMiAyOTcuMSw5MS4zIEwyOTUuMyw4Ni45IEMyOTEuNSw5MC42IDI4NSw5NS4xIDI3NC44LDk1LjEgQzI2MS44LDk1LjEgMjUyLjgsODYuOCAyNTIuOCw3Mi44IEMyNTIuNyw2MS4yIDI2Mi4yLDUyLjMgMjc4LjcsNTIuMyB6IE0yNzkuMSw4MS45IEMyODQuOSw4MS45IDI5MCw3Ny40IDI5MC44LDc1IEwyOTAuOCw2NS40IEMyOTAuOCw2NS40IDI4Ni40LDYzLjQgMjgxLjIsNjMuNCBDMjczLjcsNjMuNCAyNjkuNiw2Ni45IDI2OS42LDcyLjcgQzI2OS43LDc4LjMgMjczLjEsODEuOSAyNzkuMSw4MS45IHoiIGZpbGw9IiMwMDUwOEEiLz4KICAgIDxwYXRoIGQ9Ik0zMjQuMSwzMS40IEMzMjQuMSwyOS42IDMyNS43LDI4LjMgMzI3LjIsMjguMyBMMzMyLjQsMjguMyBDMzM0LDI4LjMgMzM0LjcsMjkgMzM1LjIsMzAuNCBMMzM3LjYsMzYuOCBDMzM5LjksMzMuNSAzNDUuOSwyNi45IDM1NiwyNi45IEMzNjMuOCwyNi45IDM3MC4xLDI5IDM2OC4zLDMzLjMgTDM2NC4xLDQyLjkgQzM2My40LDQ0LjUgMzYxLjgsNDUgMzYwLjMsNDQuMyBDMzU4LjgsNDMuNiAzNTcuMiw0MyAzNTQuMiw0MyBDMzQ3LjEsNDMgMzQyLjksNDcuNSAzNDEuOCw0OS44IEwzNDEuOCw5MC41IEMzNDEuOCw5Mi45IDM0MC4yLDkzLjYgMzM4LjEsOTMuNiBMMzI3LjIsOTMuNiBDMzI1LjYsOTMuNiAzMjQuMSw5Mi4yIDMyNC4xLDkwLjUgTDMyNC4xLDMxLjQgeiIgZmlsbD0iIzAwNTA4QSIvPgogICAgPHBhdGggZD0iTTQwMSwyNi45IEM0MDcuNSwyNi45IDQxMy43LDI4LjUgNDE2LjcsMjkuNiBMNDE2LjcsNi40IEM0MTYuNyw0LjggNDE4LjMsMy4zIDQxOS44LDMuMyBMNDMxLjIsMy4zIEM0MzIuOSwzLjMgNDM0LjMsNC45IDQzNC4zLDYuNCBMNDM0LjMsOTAuNCBDNDM0LjMsOTIuMSA0MzIuOSw5My41IDQzMS4yLDkzLjUgTDQyNiw5My41IEM0MjQuNCw5My41IDQyMy41LDkyLjIgNDIyLjksOTAuNCBMNDIxLjIsODUuMyBDNDIxLjIsODUuMyA0MTMuNyw5NSAzOTkuOSw5NSBDMzgyLjEsOTUgMzY4LjcsODAuNiAzNjguNyw2MC44IEMzNjguNyw0MS44IDM4MS41LDI2LjkgNDAxLDI2LjkgeiBNNDE3LjYsNDYuNiBDNDE3LjYsNDYuNiA0MTIuMiw0Mi45IDQwNCw0Mi45IEMzOTMuMyw0Mi45IDM4Ny44LDUxLjcgMzg3LjgsNjAuOCBDMzg3LjgsNzAgMzkzLDc4LjkgNDAzLjIsNzguOSBDNDExLjcsNzguOSA0MTYuMiw3My41IDQxNy42LDY5LjkgTDQxNy42LDQ2LjYgeiIgZmlsbD0iIzAwNTA4QSIvPgogICAgPHBhdGggZD0iTTE2MC40LDUuMiBMMTU3LjcsMTUuNiBDMTU3LjMsMTcgMTU2LjEsMTcuOSAxNTQuNywxNy45IEwxNDQuOSwxNy45IEMxNDMuNSwxNy45IDE0Mi4yLDE2LjkgMTQxLjksMTUuNiBMMTM5LjIsNS4yIEMxMzguOCwzLjcgMTM5LjYsMi4xIDE0MS4xLDEuNSBDMTQzLDAuOCAxNDYsMCAxNDkuOCwwIEMxNTMuNiwwIDE1Ni42LDAuOCAxNTguNSwxLjUgQzE2MCwyLjEgMTYwLjgsMy42IDE2MC40LDUuMiB6IiBmaWxsPSIjMDA1MDhBIi8+CiAgICA8cGF0aCBkPSJNMTYyLjMsOTAuNyBMMTU2LjMsMzEuMiBDMTU2LjIsMjkuNiAxNTQuOCwyOC40IDE1My4yLDI4LjQgTDE0Ni4zLDI4LjQgQzE0NC43LDI4LjQgMTQzLjMsMjkuNiAxNDMuMiwzMS4yIEwxMzcuMiw5MC43IEMxMzcuMSw5MS44IDEzNy42LDkyLjkgMTM4LjYsOTMuNiBMMTQ4LDk5LjggQzE0OSwxMDAuNSAxNTAuNCwxMDAuNSAxNTEuNCw5OS44IEwxNjAuOCw5My42IEMxNjEuOSw5Mi45IDE2Mi40LDkxLjggMTYyLjMsOTAuNyB6IiBmaWxsPSIjRUI2NjA4Ii8+CiAgPC9nPgo8L3N2Zz4K"/></a>';
		$this->data['footer']['logoEInvoice'] = '<img class="isdoc" src="data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHg9IjAiIHk9IjAiIHdpZHRoPSI1MTIiIGhlaWdodD0iNTEyIiB2aWV3Qm94PSIwLCAwLCA1MTIsIDUxMiI+CiAgPGcgaWQ9IkxheWVyXzEiIHRyYW5zZm9ybT0idHJhbnNsYXRlKC0wLCAwKSI+CiAgICA8cGF0aCBkPSJNOTUuNzE5LDI0OC4zOTcgQzExNC4zNTIsMjE3LjUwNSAxMzYuMzE1LDE4Ny44NjcgMTYyLjEzOSwxNjIuMDkxIEMxODcuOTYzLDEzNi4zMTUgMjE3LjU1MywxMTQuMzAzIDI0OC40NDYsOTUuNjcxIEMxMzAuMzI5LDI0LjY2NiAwLDAgMCwwIEMwLDAgMjQuNTY5LDEzMC4yODEgOTUuNzE5LDI0OC4zOTcgeiBNNDE2LjgxMiwyNjQuNDcxIEMzOTguMTc5LDI5NS4yNjggMzc2LjI2NSwzMjQuODA5IDM1MC41MzcsMzUwLjUzNyBDMzI0LjgwOSwzNzYuMjY1IDI5NS4yNjgsMzk4LjE3OSAyNjQuNDcxLDQxNi44MTIgQzM4Mi4zNDcsNDg3LjUyNyA1MTIsNTEyIDUxMiw1MTIgQzUxMiw1MTIgNDg3LjU3NSwzODIuMzk1IDQxNi44MTIsMjY0LjQ3MSB6IiBmaWxsPSIjMzg3QTIyIi8+CiAgICA8cGF0aCBkPSJNMTY4LjYyMywxNjguNjIzIEM0MC43NDQsMjk2LjQwNiAwLDUxMiAwLDUxMiBDMCw1MTIgMjE1LjM1Myw0NzEuNDAxIDM0My4zNzcsMzQzLjM3NyBDNDcxLjQwMSwyMTUuNDAxIDUxMiwtMCA1MTIsLTAgQzUxMiwtMCAyOTYuNzQ0LDQwLjUwMiAxNjguNjIzLDE2OC42MjMgeiBNOTIuNzg0LDMzMy4wOTQgTDE3Ni4yNSwzMzUuNzAxIEwxNzguODU3LDQxOS4xNjggTDkyLjc4NCwzMzMuMDk0IHogTTE3OS40ODUsMjQ2LjM5MyBMMjYyLjk1MiwyNDkgTDI2NS41NTgsMzMyLjQ2NyBMMTc5LjQ4NSwyNDYuMzkzIHogTTI2Ni4xODYsMTU5LjY5MiBMMzQ5LjY1MywxNjIuMjk5IEwzNTIuMjU5LDI0NS43NjYgTDI2Ni4xODYsMTU5LjY5MiB6IiBmaWxsPSIjNTI5QzNCIi8+CiAgPC9nPgo8L3N2Zz4K" alt="" />';
	}
}

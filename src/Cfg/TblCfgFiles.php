<?php
// TODO: remove file?

namespace Shipard\Cfg;


class TblCfgFiles extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("_TblCfgFiles", "_e10_cfg_files", "Konfigurační soubory");
	}

	public function getDetailData ($viewId, $detailId, $pk)
	{
		$detailData = new ViewDetailCfgFiles ($this, $viewId, $detailId);
		$detailData->item = $this->loadItem ($pk);
		$detailData->ok = 1;
		return $detailData;
	}

	public function getTableForm ($formOp, $pkParam, $columnValues = NULL)
	{
		$pk = $pkParam;
		$recData = array();
		switch ($formOp)
		{
			case 'new':
						$this->checkNewRec ($recData);
						break;
			case 'edit':
						if ($pk != "")
							$recData = $this->loadItem ($pk);
						break;
			case 'save':
						$data = Application::testGetData();
						$saveData = json_decode ($data, TRUE);
						$recData = $saveData ['recData'];
						break;
		}

		$formId = $this->formId ($recData);

		$f = new FormCfgFiles ($this, $formId, $formOp);

		$f->recData = $recData;
		if ($pk != "")
		{
			$f->documentPhase = "update";
		}
		return $f;
	}

	public function getTableView ($viewId, $queryParams = NULL, $requestParams = NULL)
	{
		$v = new ViewCfgFiles ($this, $viewId);
		$v->init ();
		$v->selectRows ();
		return $v;
	}

	public function loadItem ($ndx, $table = NULL)
	{
		$fileName = str_replace ('!', '/', $ndx);
		$text = file_get_contents ($fileName);
		$writable = is_writable ($fileName);
		$item = array ('ndx' => $ndx, 'fileName' => $fileName, 'text' => $text, 'writable' => $writable);
		return $item;
	}

	public function saveFormData (TableForm &$formData, $saveData = NULL)
	{
		$data = Application::testGetData();
		$saveData = json_decode ($data, true);

		$pk = $saveData ['recData']['ndx'];

		file_put_contents ($saveData ['recData']['fileName'], $saveData ['recData']['text']);

		$formData->recData = $this->loadItem ($pk);

		compileConfig();
	}
}


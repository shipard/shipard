<?php

namespace Shipard\Api\v2;
use \Shipard\Application\DataModel;
use \Shipard\Utils\Json;


class ApiResponseForm extends \Shipard\Api\v2\ApiResponse
{
  /** @var \Shipard\Form\TableForm */
  var $form;
  /** @var \Shipard\Table\DbTable */
  var $table;

  var $formOp = '';
  var $formData = NULL;

  protected function checkResponseParams()
  {
  }

  public function run()
  {
    /** @var \Shipard\Table\DbTable */
    $this->table = $this->app->table ($this->requestParams['table'] ?? '');
    $this->formOp = $this->requestParam('formOp');

    $pk = intval($this->requestParam('pk'));

    $this->form = NULL;

    /*
    if ($formData->ok)
    {
      $formData->doFormData ();
      $formData->createCode ();
      return \e10\createFormResponse ($this, $formData, $format);
    }
    */

    if ($this->table)
      $this->form = $this->table->getTableForm ($this->formOp, $pk);
    if ($this->form /*&& $this->form->ok*/)
    {
      $this->doFormData ();

      $renderer = new \Shipard\UI\ng\renderers\TableFormRenderer($this->app());
      $renderer->uiRouter = $this->uiRouter;
      $renderer->setForm($this->form);
      $renderer->render();

      $this->responseData['type'] = $this->requestParam('actionId') ?? 'INVALID';
      $this->responseData['hcFull'] = $renderer->renderedData['hcFull'];

      $this->responseData['formData'] = [];
      $this->responseData['formData']['documentPhase'] = $this->form->documentPhase;

      //error_log("###DPHS: `{$this->responseData['formData']['documentPhase']}`");

      $this->responseData['formData']['recData'] = $this->form->recData;
      $this->responseData['saveResult'] = $this->form->saveResult;
			$this->responseData['saveResult']['noCloseForm'] = intval($this->requestParam('noCloseForm'));

      Json::polish($this->responseData['formData']['recData']);
    }
    else
      error_log("___ERROR__FORM__RENDER___");
  }

	public function doFormData ()
	{
		if ($this->formOp === "new" || $this->formOp === "edit")
		{
			$this->formData = $this->requestParam('formData');
		}

		if ($this->formOp == "new")
		{
      $this->setAddParams($this->form->recData);
			$this->form->checkNewRec ();
			return;
		}
		if ($this->formOp == "edit")
		{
			return;
		}
		if ($this->formOp == "save")
		{
			$this->saveFormData2 ($this->form, $this->requestParam('formData'));
			$this->form->setRecData (NULL);
			$this->form->validForm ();
			$this->form->documentPhase = 'update';

			return;
		}

		if ($this->formOp == 'check')
		{
			$recData = $this->requestParam('formData')['recData'];
			$this->form->table->checkBeforeSave ($recData);
			//$this->saveFormData2 ($this->form, $this->requestParam('formData'));
			$this->form->recData = $recData;
			$this->form->setRecData (NULL);
			$this->form->validForm ();
			//$this->form->documentPhase = 'update';

			return;
		}
    /*
		if ($this->formOp == "listappend")
		{
			$this->listAppend ();
			return;
		}
		if ($this->formOp == "sidebar")
		{
			return;
		}
    */
	}

  public function saveFormData2 (\Shipard\Form\TableForm &$form, $formData)
	{
		$setDocState = intval($this->requestParam('set-doc-state'));
		if ($setDocState === 99000)
		{
			$this->app()->notificationsClear ($this->table->tableId(), $formData ['recData']['ndx']);

			$form->flags['reloadNotifications'] = 1;
			return;
		}

		$this->app()->db->begin();
		$needLog = 0;
		$ds = FALSE;

		//if (isset ($formData['postData']))
		//	$form->postData = $formData['postData'];

		$this->table->applySubColumnsData ($formData);

		//if (isset ($formData['changedInput']))
		//	$this->table->checkChangedInput ($formData['changedInput'], $formData);

		// prepare document state
		if ($setDocState)
		{
			$docStates = $this->table->documentStates ($formData ['recData']);
			if ($docStates)
			{
        error_log("###SDC2: `$setDocState`");
				if ($form->validNewDocumentState($setDocState, $formData))
				{
					$stateColumn = $docStates ['stateColumn'];

					$mainStateColumn = $docStates ['mainStateColumn'];
					$newMainState = $docStates ['states'][$setDocState]['mainState'];
					$formData ['recData'][$stateColumn] = $setDocState;

					$formData ['recData'][$mainStateColumn] = $newMainState;
					$ds = $docStates['states'][$setDocState];
					$this->table->setColumns($formData ['recData'], $ds);

					$this->table->checkDocumentState ($formData ['recData']);
					$needLog = 1;
				}
			}
		}

		//error_log ('#### setDocState: ' . $formData ['setDocState']);
		$form->checkBeforeSave($formData);

		// -- insert/update
		if ($formData ['documentPhase'] == 'insert')
		{
			$this->table->checkSaveData ($formData, $form->saveResult);
			$pk = $this->table->dbInsertRec ($formData ['recData']);
			$form->recData = $this->table->loadItem ($pk);
			$needLog = 1;
		}
		else
		{
			$this->table->checkSaveData ($formData, $form->saveResult);
			$pk = $this->table->dbUpdateRec ($formData ['recData']);
			$form->recData = $this->table->loadItem ($pk);
		}

		// lists
		if (isset ($formData ['lists']))
		{
			forEach ($formData ['lists'] as $listId => $listData)
			{
				$listDefinition = $this->table->listDefinition ($listId);
				$listObject = $this->app()->createObject ($listDefinition ['class']);
				$listObject->setRecord ($listId, $form);
				$listObject->saveData ($listData);
			}
		}

		// -- check after save
		if ($form->checkAfterSave())
			$this->table->dbUpdateRec ($form->recData);

		// -- save extra data
		if (isset ($formData['extra']))
			$this->table->saveExtraData($form->recData, $formData['extra']);

		// check after save - table mode
		$this->table->checkAfterSave2 ($form->recData);

		// -- save event to log
		if ($needLog)
		{
			$this->table->docsLog ($pk);
		}

		$this->app()->db->commit();

		// -- print after confirm
		if ($ds !== FALSE && isset ($ds['printAfterConfirm']))
		{
			$printCfg = [];
			$this->table->printAfterConfirm ($printCfg, $form->recData, $ds, $formData);
		}
	}

  protected function setAddParams(array &$recData)
  {
    foreach ($this->requestParams as $rpk => $v)
    {
      if (!str_starts_with($rpk, 'addparam-'))
        continue;

      //error_log("###ADP: `$rpk`");


      $colId = substr($rpk, 9);


      $colDef = $this->table->column ($colId);

      if (!$colDef)
      {
        $recData [$colId] = $v;
        continue;
      }

      switch ($colDef ['type'])
      {
          case DataModel::ctInt:
          case DataModel::ctEnumInt:
                $recData [$colId] = intval($v); break;
          case DataModel::ctDate:
          if (is_string ($v))
          {
            //if ($d == '0000-00-00')
            //	return NULL;
            $recData [$colId] = new \DateTime ($v);
          }
          else
            $recData [$colId] = $v;
          break;
        default:
          $recData [$colId] = $v;
      }
    }
  }
}

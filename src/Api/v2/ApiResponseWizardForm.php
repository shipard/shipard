<?php

namespace Shipard\Api\v2;
use \Shipard\Utils\Json;


/**
 * class ApiResponseWizardForm
 */
class ApiResponseWizardForm extends \Shipard\Api\v2\ApiResponse
{
  /** @var \Shipard\Form\Wizard */
  var $form;

  var $formOp = '';
  var $formData = NULL;

  protected function checkResponseParams()
  {
  }

  public function run()
  {
    /** @var \Shipard\Table\DbTable */
    $this->formOp = $this->requestParam('formOp');

    $this->form = NULL;

		$wizardClass = $this->requestParam('class-id');
		if ($wizardClass)
			$this->form = $this->app()->createObject($wizardClass);
    if ($this->form /*&& $this->form->ok*/)
    {
      $this->form->requestParams = $this->requestParams;
      $this->form->pageNumber = intval($this->requestParam('pageNumber') ?? 0);
      $fd = $this->requestParam('formData');
      if ($fd)
        $this->form->recData = $fd['recData'] ?? [];

      $renderer = new \Shipard\UI\ng\renderers\WizardFormRenderer($this->app());
      $renderer->uiRouter = $this->uiRouter;
      $renderer->setWizard($this->form);
      $renderer->render();

      $this->responseData['type'] = $this->requestParam('actionId') ?? 'INVALID';
      $this->responseData['hcFull'] = $renderer->renderedData['hcFull'];

      $this->responseData['formData'] = [];
      $this->responseData['formData']['documentPhase'] = $this->form->documentPhase;

      $this->responseData['formData']['recData'] = $this->form->recData;
      $this->responseData['saveResult'] = $this->form->saveResult;
			$this->responseData['saveResult']['noCloseForm'] = intval($this->requestParam('noCloseForm'));
      Json::polish($this->responseData['formData']['recData']);
    }
    else
      error_log("___ERROR__GUIDE_FORM__RENDER___");
  }
}

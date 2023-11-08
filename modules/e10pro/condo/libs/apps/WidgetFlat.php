<?php

namespace e10pro\condo\libs\apps;
use \Shipard\Utils\Utils, \Shipard\Utils\Str, \Shipard\Utils\Json ;



/**
 * Class WidgetFlat
 */
class WidgetFlat extends \Shipard\UI\Core\UIWidget
{
	var $flatNdx = 0;
	var $userContext = NULL;
  var \e10pro\condo\libs\FlatInfo $flatInfo;

	function loadData()
	{
    $this->flatInfo = new \e10pro\condo\libs\FlatInfo($this->app());
    $this->flatInfo->setWorkOrder($this->flatNdx);
    $this->flatInfo->loadInfo();

	}

	function renderData()
	{
    $this->uiTemplate->data['contents'] = [];

    $contentTitle = ['text' => 'Informace o bytové jednotce', 'class' => 'h3'];
    foreach ($this->flatInfo->data['vdsContent'] as $cc)
    {
      $cc['params'] = ['hideHeader' => 1, ];
      $cc['title'] = $contentTitle;
      $this->uiTemplate->data['contents'][] = $cc;

      $this->uiTemplate->data['flatProperties'] = $cc['table'];
      $this->uiTemplate->data['flatPropertiesStruct'] = Json::lint($cc['table']);

      break;
    }

    if ($this->flatInfo->data['personsList'])
    {
      $contentTitlePersons = ['text' => 'Kontaktní údaje', 'class' => 'h3'];
      $cc = $this->flatInfo->data['personsList'];
      unset($cc['pane']);
      $cc['title'] = $contentTitlePersons;
      $this->uiTemplate->data['contents'][] = $cc;

      $this->uiTemplate->data['personList'] = $cc['table'];
      $this->uiTemplate->data['personListStruct'] = Json::lint($cc['table']);
    }

    if ($this->flatInfo->data['rowsContent'])
		{
			$contentTitleAdvances = ['text' => 'Výše měsíčních záloh', 'class' => 'h3'];
			$cc = $this->flatInfo->data['rowsContent'];
			unset($cc['pane']);
			$cc['title'] = $contentTitleAdvances;

			$this->uiTemplate->data['contents'][] = $cc;

      $this->uiTemplate->data['advancesList'] = $cc['table'];
      $this->uiTemplate->data['advancesListStruct'] = Json::lint($cc['table']);
      $this->uiTemplate->data['advancesListContent'] = Json::lint($cc);
		}

		$templateStr = $this->uiTemplate->subTemplateStr('modules/e10pro/condo/libs/apps/subtemplates/flatInfo');
		$code = $this->uiTemplate->render($templateStr);
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $code]);
	}

	public function createContent ()
	{
		$userContexts = $this->app()->uiUserContext ();
		$ac = $userContexts['contexts'][$this->app()->uiUserContextId] ?? NULL;
		if ($ac)
			$this->flatNdx = $ac['flatNdx'] ?? 0;

		$this->userContext = $userContexts['condo']['flats'][$this->flatNdx];

		$this->loadData();
		$this->renderData();
	}

	public function title()
	{
		return FALSE;
	}
}

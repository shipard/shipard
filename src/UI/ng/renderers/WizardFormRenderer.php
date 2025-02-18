<?php

namespace Shipard\UI\ng\renderers;


/**
 * class WizardFormRenderer
 */
class WizardFormRenderer extends \Shipard\UI\ng\renderers\TableFormRenderer
{
  var ?\Shipard\Form\Wizard $wizard = NULL;

  public function setWizard (\Shipard\Form\Wizard $wizard)
  {
    $this->wizard = $wizard;
    $this->setForm($wizard);
    $this->wizard->ngRenderer = $this;
  }

  public function render()
  {
    $this->wizard->doStep ();
		$this->wizard->renderForm();

    $this->renderedData['hcHeader'] = $this->createHeaderCode();

		$this->renderedData['hcContent'] = '';
    $this->createContentCode();

    $hcFull = '';
    $hcFull .= "<div class='formContainer' data-wizard-page='".$this->wizard->pageNumber."'>";
      $hcFull .= "<div class='formHeader'>".$this->renderedData['hcHeader']."</div>";
      $hcFull .= "<div class='formTabs'></div>";
      $hcFull .= "<div class='formContent'>".$this->renderedData['hcContent']."</div>";
    $hcFull .= "</div>";

    $this->renderedData['hcFull'] = $hcFull;
  }
}


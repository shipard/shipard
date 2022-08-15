<?php

namespace e10doc\templates\libs;
use \Shipard\Utils\Utils, \Shipard\Utils\Str;


/**
 * class GeneratorFromExistedDoc
 */
class GeneratorFromExistedDoc extends \e10doc\templates\libs\Generator
{
	public function createDocument ()
	{
    $srcDocNdx = $this->templateRecData['srcDocLast'];
    if (!$srcDocNdx)
      $srcDocNdx = $this->templateRecData['srcDocOrigin'];

    if (!$srcDocNdx)
      return 0;

    $srcDocRecData = $this->tableDocsHeads->loadItem($srcDocNdx);

    $linkId = 'TMPL:'.$this->templateNdx.';SRCDOC:'.$srcDocNdx.'P:'.$this->scheduler->periodBegin->format('Ymd').'.'.$this->scheduler->periodEnd->format('Ymd').';';

    $newDocNdx = $this->tableDocsHeads->copyDocument ($srcDocNdx, 0);
    $this->invHead = $this->tableDocsHeads->loadItem($newDocNdx);
		$this->invHead ['template'] = $this->templateRecData['ndx'];
    $this->invHead ['linkId'] = $linkId;
		$this->invHead ['datePeriodBegin'] = $this->scheduler->periodBegin;
		$this->invHead ['datePeriodEnd'] = $this->scheduler->periodEnd;
		$this->invHead ['dateIssue'] = $this->scheduler->today;
		$this->invHead ['dateAccounting'] = $this->scheduler->dateAccounting;
		$this->invHead ['dateTax'] = $this->scheduler->dateAccounting;

    $this->invHead ['symbol1'] = '';
    $this->invHead ['symbol2'] = '';

		$this->invHead ['dateDue'] = new \DateTime ($this->invHead ['dateTax']->format('Y-m-d'));

    $this->docNote = '';

		$dd = Utils::dateDiff($srcDocRecData['dateIssue'], $srcDocRecData['dateDue']);
		if (!$dd)
			$dd = 14;
		$this->invHead ['dateDue']->add (new \DateInterval('P'.$dd.'D'));

    $this->variables->setDataItem('docHead', $this->invHead);

    // -- resolve variables
    $tt = trim($this->variables->resolve($this->templateRecData['docText']));
    if ($tt !== '')
      $this->invHead ['title'] = Str::upToLen($tt, 120);

    $tt = trim($this->variables->resolve($this->templateRecData['symbol1']));
    if ($tt !== '')
      $this->invHead ['symbol1'] = Str::upToLen($tt, 20);

    $tt = trim($this->variables->resolve($this->templateRecData['symbol2']));
    if ($tt !== '')
      $this->invHead ['symbol2'] = Str::upToLen($tt, 20);

		if ($this->scheduler->debug === 2)
		{
			if (!$this->scheduler->cntGeneratedDocs)
				echo "\n    | begin        end        | dateIssue  | dateAcc    | dateDue\n";
			//            - 2021-04-01 - 2021-04-30 | 2021-02-05 | 2021-04-01 | 2021-04-01
			echo "    - ".$this->invHead ['datePeriodBegin']->format('Y-m-d').' - '.$this->invHead ['datePeriodEnd']->format('Y-m-d');
			echo " | ".$this->invHead ['dateIssue']->format('Y-m-d')." | ".$this->invHead ['dateAccounting']->format('Y-m-d');
			echo " | ".$this->invHead ['dateDue']->format('Y-m-d');
			echo "\n";
		}

		$this->scheduler->cntGeneratedDocs++;

    return $newDocNdx;
	}

  protected function doDoc()
  {
    $this->scheduler->reviewData [$this->scheduler->dateId]['templates'][$this->templateNdx]['cntDocs']++;

    if ($this->scheduler->save)
    {
      $newDocNdx = $this->createDocument();
      if (!$newDocNdx)
        return;

      $this->db()->query ('UPDATE [e10doc_core_heads] SET ', $this->invHead, ' WHERE [ndx] = %i', $newDocNdx);
      $this->saveDocument($this->templateRecData, $newDocNdx);

      //if ($this->templateRecData['dstDocAutoSend'] !== TableHeads::asmNone)
      //  $this->send($newDocNdx);
    }
  }

  public function run()
  {
    $this->doDoc();
  }
}

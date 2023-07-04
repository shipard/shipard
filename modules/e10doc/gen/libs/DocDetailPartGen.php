<?php

namespace e10doc\gen\libs;


/**
 * class DocDetailPartGen
 */
class DocDetailPartGen extends \e10doc\base\libs\DocDetailPart
{
  public function doIt_From()
  {
    $q = [];
    array_push($q, 'SELECT requests.cfg, requests.srcType, requests.srcDocument, requests.dstDocument,');
    array_push($q, ' docHeads.docNumber, docHeads.docState, docHeads.docStateMain, docHeads.docType, docHeads.ndx');
    array_push($q, ' FROM [e10doc_gen_requests] AS requests');
    array_push($q, ' LEFT JOIN [e10doc_core_heads] AS docHeads ON requests.srcDocument = docHeads.ndx');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND requests.[dstDocument] = %i', $this->recData['ndx']);

    $existedDocs = [];
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $existedDocs[] = $r->toArray();
    }

    if (!count($existedDocs))
      return;


    $test = [];
    $test[] = ['text' => 'Vystaveno z dokladu', 'class' => 'h2'];

    foreach ($existedDocs as $doc)
    {
      $openButton = [
        'docAction' => 'edit',
        'text' => $doc['docNumber'], 'pk' => $doc['srcDocument'], 'table' => 'e10doc.core.heads',
        'icon' => $this->tableDocsHeads->tableIcon($doc),
        'type' => 'button',
        'actionClass' => 'btn btn-primary',
        'class' => 'pull-right'
      ];
      $test [] = $openButton;

      $this->addContent(['type' => 'line', 'pane' => 'e10-pane e10-pane-table', 'line' => $test]);
    }
  }

  public function doIt()
  {
    if (!$this->gens)
      return;

    $this->doIt_From();

    $existedDocs = [];

    $q = [];
    array_push($q, 'SELECT requests.cfg, requests.srcType, requests.srcDocument, requests.dstDocument,');
    array_push($q, ' docHeads.docNumber, docHeads.docState, docHeads.docStateMain, docHeads.docType, docHeads.ndx');
    array_push($q, ' FROM [e10doc_gen_requests] AS requests');
    array_push($q, ' LEFT JOIN [e10doc_core_heads] AS docHeads ON requests.dstDocument = docHeads.ndx');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND requests.[srcDocument] = %i', $this->recData['ndx']);

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $existedDocs[$r['cfg']][] = $r->toArray();
    }

    foreach ($this->gens as $genId => $gen)
    {
      if (isset($existedDocs[$genId]))
      {
        foreach ($existedDocs[$genId] as $doc)
        {
          $test = [];

          $this->tableDocsHeads->createPrintToolbar ($test, $doc);
          foreach ($test as $btnNdx => $btn)
            $test[$btnNdx]['class'] = 'pull-right';

          $test[] = ['text' => $gen['fn'], 'class' => 'h2'];

          $docStates = $this->tableDocsHeads->documentStates ($doc);
          $docStateClass = $this->tableDocsHeads->getDocumentStateInfo ($docStates, $doc, 'styleClass');

          $openButton = [
            'docAction' => 'edit',
            'text' => $doc['docNumber'], 'pk' => $doc['dstDocument'], 'table' => 'e10doc.core.heads',
            'icon' => $this->tableDocsHeads->tableIcon($doc),
            'type' => 'button',
            'actionClass' => 'btn btn-primary',
            'class' => 'pull-right'
          ];
          $test [] = $openButton;

          $this->addContent(['type' => 'line', 'pane' => 'e10-pane e10-pane-table e10-ds-block '.$docStateClass, 'line' => $test]);
        }

        continue;
      }

      if ($gen['srcDocType'] !== $this->recData['docType'])
        continue;

      $test = [];
      $test[] = ['text' => $gen['fn'], 'class' => 'h2'];

      $test [] = [
        'type' => 'action', 'action' => 'addwizard',
        'text' => 'Vygenerovat', 'data-class' => 'e10doc.gen.libs.WizardDocGen',
        'icon' => 'cmnbkpRegenerateOpenedPeriod',
        'data-addparams' => 'docGenCfg='.$gen['ndx'],
        'class' => 'pull-right'
      ];

      $this->addContent(['type' => 'line', 'pane' => 'e10-pane e10-pane-table', 'line' => $test]);
    }
  }

  public function create()
  {
    $this->doIt();
  }
}

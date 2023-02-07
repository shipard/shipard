<?php

namespace e10doc\core\libs;

use \e10\TableForm, \e10\utils, \e10\Utility;


/**
 * Class DocumentsErase
 * @package e10doc\core\libs
 */
class DocEraser extends \lib\docs\utils\DocEraserCore
{
	public function eraseDocument($docNdx)
	{
		$this->docNdx = $docNdx;

		$this->eraseAttachments('e10doc.core.heads', $docNdx);
		$this->eraseClassification('e10doc.core.heads', $docNdx);
		$this->eraseDocLinks('e10doc.core.heads', $docNdx);
		$this->eraseHistory('e10doc.core.heads', $docNdx);

		$this->eraseRows();
		$this->eraseRowsTaxes();
		$this->eraseAccJournal();
		$this->eraseInventory();
		$this->eraseBalance();

		$this->db()->query ('DELETE FROM [e10doc_core_heads] WHERE [ndx] = %i', $this->docNdx);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
	}

	protected function eraseRows()
	{
		$this->db()->query ('DELETE FROM [e10doc_core_rows] WHERE [document] = %i', $this->docNdx);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
	}

	protected function eraseRowsTaxes()
	{
		$this->db()->query ('DELETE FROM [e10doc_core_taxes] WHERE [document] = %i', $this->docNdx);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
	}

	protected function eraseAccJournal()
	{
		$this->db()->query ('DELETE FROM [e10doc_debs_journal] WHERE [document] = %i', $this->docNdx);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
	}

	protected function eraseInventory()
	{
		if ($this->app()->model()->table ('e10doc.inventory.journal') === FALSE)
			return;
		$this->db()->query ('DELETE FROM [e10doc_inventory_journal] WHERE [docHead] = %i', $this->docNdx);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
	}

	protected function eraseBalance()
	{
		$this->db()->query ('DELETE FROM [e10doc_balance_journal] WHERE docHead = %i', $this->docNdx);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
	}
}

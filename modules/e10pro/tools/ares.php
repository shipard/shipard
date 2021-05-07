<?php

namespace Tools {

class Ares
{
	private $ic;
	private $subject = array ();

	public function setIc ($ic)
	{
		$this->ic = intval ($ic);
	}

	public function load ()
	{
		define('ARESURL','http://wwwinfo.mfcr.cz/cgi-bin/ares/darv_bas.cgi?ico=');

		$file = @file_get_contents (ARESURL . $this->ic);

		if ($file)
			$xml = @simplexml_load_string ($file);
		if ($xml) 
		{
			$ns = $xml->getDocNamespaces();
			$data = $xml->children($ns['are']);
			$el = $data->children($ns['D'])->VBAS;
			if (strval($el->ICO) == $this->ic)
			{
				$this->subject ['ic'] = strval ($el->ICO);
				$this->subject ['dic'] = strval ($el->DIC);
				$this->subject ['name'] = strval ($el->OF);
				$this->subject ['street']= strval ($el->AA->NU) . ' ' . strval($el->AA->CD);
				$this->subject ['city']= strval ($el->AA->N);
				$this->subject ['zip']= strval ($el->AA->PSC);
				$this->subject ['state'] = 'ok';
			}
			else
				$this->subject ['state'] = 'nonex';
		}
		else
			$this->subject ['state'] = 'error';

		return $this->subject;
	}

	public function subject () {return $this->subject;}
} // class Ares


} // namespace Tools


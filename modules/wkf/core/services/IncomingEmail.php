<?php


namespace wkf\core\services;

use \PhpMimeMailParser\Parser, \PhpMimeMailParser\Attachment, e10\Utility, e10\utils, wkf\core\TableIssues;
use \e10doc\core\libs\DocsModes;
use \Shipard\Utils\Str;


/**
 * Class IncomingEmail
 * @package wkf\core\services
 */
class IncomingEmail extends Utility
{
	var $emailFileName;

	/** @var Parser */
	var $emailParser;

	/** @var \wkf\core\TableIssues */
	var $tableIssues;

	var $headerFrom;
	var $headerTo;
	var $emails = array ();
	var $coreEmails = array ();
	var $newMsgNdx = 0;

	static $emlHeadersAddr = ['from' => 'from', 'to' => 'to'];
	static $emlHeadersOther = ['message-id', 'in-reply-to', 'mailing-list', 'list-id', 'return-path', 'x-original-sender'];

	function setFileName ($fn)
	{
		$this->tableIssues = $this->app()->table('wkf.core.issues');

		$this->emailFileName = $fn;
		$this->emailParser = new Parser();
		$this->emailParser->setPath($fn);
	}

	function addToIssues($subAddress)
	{
		$issueType = -1;
		$issueKind = 0;
		$section = 0;
		$existedShipardEmail = $this->app()->cfgItem ('wkf.shipardEmails.'.$subAddress, NULL);

		$docState = 1001;
		$docStateMain = 0;

		if ($existedShipardEmail !== NULL)
		{
			if ($existedShipardEmail['type'] === 'section')
			{
				$section = $existedShipardEmail['dstNdx'];
				$ik = $this->tableIssues->sectionIssueKind($section);
				if ($ik)
				{
					$issueType = $ik['issueType'];
					$issueKind = $ik['ndx'];
				}
			}
		}

		if ($issueType === -1)
		{
			$issueType = 1;
			$issueKind = $this->tableIssues->issueKindDefault($issueType, TRUE);
		}

		if (!$section)
			$section = $this->tableIssues->defaultSection(20);

		$issueStatus = $this->tableIssues->sectionStatus ($section);

		$issueRecData = [
			'section' => $section, 'issueType' => $issueType, 'issueKind' => $issueKind, 'status' => $issueStatus,
			'dateCreate' => new \DateTime(), 'dateIncoming' => new \DateTime(),
			'structVersion' => $this->tableIssues->currentStructVersion,
			'source' => TableIssues::msEmail, 'issueId' => '', 'docState' => $docState, 'docStateMain' => $docStateMain
		];

		$subject = $this->emailParser->getHeader('subject');
		$issueRecData['subject'] = Str::upToLen($this->__decodeHeader($subject), 100);

		$text = $this->emailParser->getMessageBody('text');
		$html = $this->emailParser->getMessageBody('html');

		if ($html !== '')
			$issueRecData['body'] = $html;
		else
			$issueRecData['body'] = $text;

		// -- persons
		$to = $this->emailParser->getHeader('to');
		$this->headerTo = $this->parseHeader ($to);

		$from = $this->emailParser->getHeader('from');
		$this->headerFrom = $this->parseHeader ($from);

		$this->checkEmails ();

		// -- system info
		$systemInfo = $this->systemInfo();
		$issueRecData['systemInfo'] = json_encode($systemInfo);

		$newMsgNdx = $this->tableIssues->dbInsertRec ($issueRecData);
		$issueRecData['ndx'] = $newMsgNdx;
		$this->tableIssues->checkAfterSave2($issueRecData);
		$this->newMsgNdx = $newMsgNdx;

		$this->setPersons ('wkf-issues-from', $this->headerFrom);
		$this->setPersons ('wkf-issues-notify', $this->headerTo);


		// -- attachments
		$attachments = $this->emailParser->getAttachments();
		foreach ($attachments as $a)
		{
			$afn = $a->getFilename ();
			$origbn = $this->__decodeHeader($afn);

			$path_parts = pathinfo ($origbn);
			$baseFileName = utils::safeChars ($path_parts ['filename']);
			$fileType = isset($path_parts ['extension']) ? $path_parts ['extension'] : '';
			$attName = $path_parts ['filename'];

			$attFileName = $baseFileName.'-'.base_convert(time() + rand(), 10, 35).'.'.$fileType;
			$attFullFileName = __APP_DIR__.'/tmp/'.$attFileName;

			$attTempFileName = $a->save (__APP_DIR__.'/tmp/', Parser::ATTACHMENT_RANDOM_FILENAME);
			rename($attTempFileName, $attFullFileName);

			$attFullFileName = $this->checkFile($attFullFileName);
			$path_parts = pathinfo ($attFullFileName);
			$fileType = isset($path_parts ['extension']) ? $path_parts ['extension'] : '';
			$attOrder = 1000;
			if ($fileType === 'pdf')
				$attOrder = 100;

			$fileCheckSum = sha1_file($attFullFileName);
			$attExist = $this->db()->query('SELECT ndx FROM [e10_attachments_files] WHERE recid = %i', $newMsgNdx,
										' AND [tableid] = %s', 'wkf.core.issues', ' AND [fileCheckSum] = %s', $fileCheckSum)->fetch();
			if ($attExist)
				continue;

			\E10\Base\addAttachments ($this->app, 'wkf.core.issues', $newMsgNdx, $attFullFileName, '', true, $attOrder, $attName);
		}

		copy ($this->emailFileName, utils::tmpFileName('inbox.eml'));
		unlink ($this->emailFileName);

		// -- filters
		$issueRecData = $this->tableIssues->loadItem($newMsgNdx);
		$if = new \wkf\core\libs\IssueFiltering($this->app());
		$if->setIssue($issueRecData);
		$if->applyFilters();

		// -- finish
		$issueRecData = $this->tableIssues->loadItem($newMsgNdx);
		$this->tableIssues->dbUpdateRec($issueRecData);
		$issueRecData = $this->tableIssues->loadItem($newMsgNdx);
		$this->tableIssues->checkAfterSave2($issueRecData);

		$this->tableIssues->docsLog ($newMsgNdx);

		$checkIncomingIssues = intval($this->app()->cfgItem('options.experimental.checkIncomingIssues', 0));
		if ($checkIncomingIssues)
		{
			$im = new \wkf\core\libs\CheckIncomingIssue($this->app());
			$im->setIssue($newMsgNdx);
			$im->run();
		}

		return TRUE;
	}

	function checkFile($srcFileName)
	{
		$path_parts = pathinfo ($srcFileName);

		$fileType = isset($path_parts ['extension']) ? $path_parts ['extension'] : '';
		if ($fileType !== '')
			return $srcFileName;

		$newFileName = $srcFileName;

		$mimeType = mime_content_type($srcFileName);
		$ext = $this->mimeTypeToExt($mimeType);
		if (substr($newFileName, -1) !== '.')
			$newFileName .= '.';
		$newFileName .= $ext;

		rename($srcFileName, $newFileName);

		return $newFileName;
	}

	function mimeTypeToExt($mime)
	{
		$mime_map = [
			'video/3gpp2'                                                               => '3g2',
			'video/3gp'                                                                 => '3gp',
			'video/3gpp'                                                                => '3gp',
			'application/x-compressed'                                                  => '7zip',
			'audio/x-acc'                                                               => 'aac',
			'audio/ac3'                                                                 => 'ac3',
			'application/postscript'                                                    => 'ai',
			'audio/x-aiff'                                                              => 'aif',
			'audio/aiff'                                                                => 'aif',
			'audio/x-au'                                                                => 'au',
			'video/x-msvideo'                                                           => 'avi',
			'video/msvideo'                                                             => 'avi',
			'video/avi'                                                                 => 'avi',
			'application/x-troff-msvideo'                                               => 'avi',
			'application/macbinary'                                                     => 'bin',
			'application/mac-binary'                                                    => 'bin',
			'application/x-binary'                                                      => 'bin',
			'application/x-macbinary'                                                   => 'bin',
			'image/bmp'                                                                 => 'bmp',
			'image/x-bmp'                                                               => 'bmp',
			'image/x-bitmap'                                                            => 'bmp',
			'image/x-xbitmap'                                                           => 'bmp',
			'image/x-win-bitmap'                                                        => 'bmp',
			'image/x-windows-bmp'                                                       => 'bmp',
			'image/ms-bmp'                                                              => 'bmp',
			'image/x-ms-bmp'                                                            => 'bmp',
			'application/bmp'                                                           => 'bmp',
			'application/x-bmp'                                                         => 'bmp',
			'application/x-win-bitmap'                                                  => 'bmp',
			'application/cdr'                                                           => 'cdr',
			'application/coreldraw'                                                     => 'cdr',
			'application/x-cdr'                                                         => 'cdr',
			'application/x-coreldraw'                                                   => 'cdr',
			'image/cdr'                                                                 => 'cdr',
			'image/x-cdr'                                                               => 'cdr',
			'zz-application/zz-winassoc-cdr'                                            => 'cdr',
			'application/mac-compactpro'                                                => 'cpt',
			'application/pkix-crl'                                                      => 'crl',
			'application/pkcs-crl'                                                      => 'crl',
			'application/x-x509-ca-cert'                                                => 'crt',
			'application/pkix-cert'                                                     => 'crt',
			'text/css'                                                                  => 'css',
			'text/x-comma-separated-values'                                             => 'csv',
			'text/comma-separated-values'                                               => 'csv',
			'application/vnd.msexcel'                                                   => 'csv',
			'application/x-director'                                                    => 'dcr',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
			'application/x-dvi'                                                         => 'dvi',
			'message/rfc822'                                                            => 'eml',
			'application/x-msdownload'                                                  => 'exe',
			'video/x-f4v'                                                               => 'f4v',
			'audio/x-flac'                                                              => 'flac',
			'video/x-flv'                                                               => 'flv',
			'image/gif'                                                                 => 'gif',
			'application/gpg-keys'                                                      => 'gpg',
			'application/x-gtar'                                                        => 'gtar',
			'application/x-gzip'                                                        => 'gzip',
			'application/mac-binhex40'                                                  => 'hqx',
			'application/mac-binhex'                                                    => 'hqx',
			'application/x-binhex40'                                                    => 'hqx',
			'application/x-mac-binhex40'                                                => 'hqx',
			'text/html'                                                                 => 'html',
			'image/x-icon'                                                              => 'ico',
			'image/x-ico'                                                               => 'ico',
			'image/vnd.microsoft.icon'                                                  => 'ico',
			'text/calendar'                                                             => 'ics',
			'application/java-archive'                                                  => 'jar',
			'application/x-java-application'                                            => 'jar',
			'application/x-jar'                                                         => 'jar',
			'image/jp2'                                                                 => 'jp2',
			'video/mj2'                                                                 => 'jp2',
			'image/jpx'                                                                 => 'jp2',
			'image/jpm'                                                                 => 'jp2',
			'image/jpeg'                                                                => 'jpeg',
			'image/pjpeg'                                                               => 'jpeg',
			'application/x-javascript'                                                  => 'js',
			'application/json'                                                          => 'json',
			'text/json'                                                                 => 'json',
			'application/vnd.google-earth.kml+xml'                                      => 'kml',
			'application/vnd.google-earth.kmz'                                          => 'kmz',
			'text/x-log'                                                                => 'log',
			'audio/x-m4a'                                                               => 'm4a',
			'audio/mp4'                                                                 => 'm4a',
			'application/vnd.mpegurl'                                                   => 'm4u',
			'audio/midi'                                                                => 'mid',
			'application/vnd.mif'                                                       => 'mif',
			'video/quicktime'                                                           => 'mov',
			'video/x-sgi-movie'                                                         => 'movie',
			'audio/mpeg'                                                                => 'mp3',
			'audio/mpg'                                                                 => 'mp3',
			'audio/mpeg3'                                                               => 'mp3',
			'audio/mp3'                                                                 => 'mp3',
			'video/mp4'                                                                 => 'mp4',
			'video/mpeg'                                                                => 'mpeg',
			'application/oda'                                                           => 'oda',
			'audio/ogg'                                                                 => 'ogg',
			'video/ogg'                                                                 => 'ogg',
			'application/ogg'                                                           => 'ogg',
			'font/otf'                                                                  => 'otf',
			'application/x-pkcs10'                                                      => 'p10',
			'application/pkcs10'                                                        => 'p10',
			'application/x-pkcs12'                                                      => 'p12',
			'application/x-pkcs7-signature'                                             => 'p7a',
			'application/pkcs7-mime'                                                    => 'p7c',
			'application/x-pkcs7-mime'                                                  => 'p7c',
			'application/x-pkcs7-certreqresp'                                           => 'p7r',
			'application/pkcs7-signature'                                               => 'p7s',
			'application/pdf'                                                           => 'pdf',
			'application/octet-stream'                                                  => 'pdf',
			'application/x-x509-user-cert'                                              => 'pem',
			'application/x-pem-file'                                                    => 'pem',
			'application/pgp'                                                           => 'pgp',
			'application/x-httpd-php'                                                   => 'php',
			'application/php'                                                           => 'php',
			'application/x-php'                                                         => 'php',
			'text/php'                                                                  => 'php',
			'text/x-php'                                                                => 'php',
			'application/x-httpd-php-source'                                            => 'php',
			'image/png'                                                                 => 'png',
			'image/x-png'                                                               => 'png',
			'application/powerpoint'                                                    => 'ppt',
			'application/vnd.ms-powerpoint'                                             => 'ppt',
			'application/vnd.ms-office'                                                 => 'ppt',
			'application/msword'                                                        => 'doc',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
			'application/x-photoshop'                                                   => 'psd',
			'image/vnd.adobe.photoshop'                                                 => 'psd',
			'audio/x-realaudio'                                                         => 'ra',
			'audio/x-pn-realaudio'                                                      => 'ram',
			'application/x-rar'                                                         => 'rar',
			'application/rar'                                                           => 'rar',
			'application/x-rar-compressed'                                              => 'rar',
			'audio/x-pn-realaudio-plugin'                                               => 'rpm',
			'application/x-pkcs7'                                                       => 'rsa',
			'text/rtf'                                                                  => 'rtf',
			'text/richtext'                                                             => 'rtx',
			'video/vnd.rn-realvideo'                                                    => 'rv',
			'application/x-stuffit'                                                     => 'sit',
			'application/smil'                                                          => 'smil',
			'text/srt'                                                                  => 'srt',
			'image/svg+xml'                                                             => 'svg',
			'application/x-shockwave-flash'                                             => 'swf',
			'application/x-tar'                                                         => 'tar',
			'application/x-gzip-compressed'                                             => 'tgz',
			'image/tiff'                                                                => 'tiff',
			'font/ttf'                                                                  => 'ttf',
			'text/plain'                                                                => 'txt',
			'text/x-vcard'                                                              => 'vcf',
			'application/videolan'                                                      => 'vlc',
			'text/vtt'                                                                  => 'vtt',
			'audio/x-wav'                                                               => 'wav',
			'audio/wave'                                                                => 'wav',
			'audio/wav'                                                                 => 'wav',
			'application/wbxml'                                                         => 'wbxml',
			'video/webm'                                                                => 'webm',
			'image/webp'                                                                => 'webp',
			'audio/x-ms-wma'                                                            => 'wma',
			'application/wmlc'                                                          => 'wmlc',
			'video/x-ms-wmv'                                                            => 'wmv',
			'video/x-ms-asf'                                                            => 'wmv',
			'font/woff'                                                                 => 'woff',
			'font/woff2'                                                                => 'woff2',
			'application/xhtml+xml'                                                     => 'xhtml',
			'application/excel'                                                         => 'xl',
			'application/msexcel'                                                       => 'xls',
			'application/x-msexcel'                                                     => 'xls',
			'application/x-ms-excel'                                                    => 'xls',
			'application/x-excel'                                                       => 'xls',
			'application/x-dos_ms_excel'                                                => 'xls',
			'application/xls'                                                           => 'xls',
			'application/x-xls'                                                         => 'xls',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
			'application/vnd.ms-excel'                                                  => 'xlsx',
			'application/xml'                                                           => 'xml',
			'text/xml'                                                                  => 'xml',
			'text/xsl'                                                                  => 'xsl',
			'application/xspf+xml'                                                      => 'xspf',
			'application/x-compress'                                                    => 'z',
			'application/x-zip'                                                         => 'zip',
			'application/zip'                                                           => 'zip',
			'application/x-zip-compressed'                                              => 'zip',
			'application/s-compressed'                                                  => 'zip',
			'multipart/x-zip'                                                           => 'zip',
			'text/x-scriptzsh'                                                          => 'zsh',
		];

		return isset($mime_map[$mime]) ? $mime_map[$mime] : '---';
	}

	function scan ()
	{
		$docsInScanMode = DocsModes::get($this->app, DocsModes::dmScanToDocument);
		if ($docsInScanMode === FALSE)
			return FALSE;

		if (count($docsInScanMode) !== 1)
			return FALSE;

		$scanDoc = $docsInScanMode[0];

		$attachments = $this->emailParser->getAttachments();
		foreach ($attachments as $a)
		{
			$afn = $a->getFilename ();
			$origbn = $this->__decodeHeader($afn);

			$path_parts = pathinfo ($origbn);
			$baseFileName = utils::safeChars ($path_parts ['filename']);
			$fileType = isset($path_parts ['extension']) ? $path_parts ['extension'] : '';

			$attFileName = $baseFileName.'-'.base_convert(time() + rand(), 10, 35).'.'.$fileType;

			$a->saveAttachment (__APP_DIR__.'/tmp/', $attFileName);

			\E10\Base\addAttachments ($this->app, $scanDoc['tableId'], $scanDoc['recId'], __APP_DIR__.'/tmp/'.$attFileName, '', TRUE);
		}

		unlink ($this->emailFileName);

		return TRUE;
	}

	function addToDocuments()
	{
		$tableDocuments = $this->app()->table('e10pro.wkf.documents');

		$docRecData = [
			'type' => 'common',
			'dateCreate' => new \DateTime(),
			'docState' => 1000, 'docStateMain' => 0
		];

		$subject = $this->emailParser->getHeader('subject');
		$docRecData ['title'] = $this->__decodeHeader($subject);

		$text = $this->emailParser->getMessageBody('text');
		if ($text !== false)
			$docRecData ['text'] = $text;

		$newDocNdx = $tableDocuments->dbInsertRec ($docRecData);
		$this->addAttachmentsToRec ('e10pro.wkf.documents', $newDocNdx);
		$tableDocuments->docsLog ($newDocNdx);

		return TRUE;
	}

	public function addToOutbox ()
	{
		return TRUE;
	}

	public function addToTray ($shipardEmail)
	{
		$this->addAttachmentsToRec ('wkf.base.trays', $shipardEmail['dstNdx']);

		return TRUE;
	}

	public function addAttachmentsToRec ($tableNdx, $recNdx)
	{
		$attachments = $this->emailParser->getAttachments();
		foreach ($attachments as $a)
		{
			$afn = $a->getFilename ();
			$origbn = $this->__decodeHeader($afn);

			$path_parts = pathinfo ($origbn);
			$baseFileName = utils::safeChars ($path_parts ['filename']);
			$fileType = isset($path_parts ['extension']) ? $path_parts ['extension'] : '';

			$attFileName = $baseFileName.'-'.base_convert(time() + rand(), 10, 35).'.'.$fileType;

			$a->saveAttachment (__APP_DIR__.'/tmp/', $attFileName);



			\E10\Base\addAttachments ($this->app, $tableNdx, $recNdx, __APP_DIR__.'/tmp/'.$attFileName, '', TRUE);
		}

		unlink ($this->emailFileName);
	}

	public function run ()
	{
		$subAddress = $this->app->testGetParam('subAddress');
		if ($subAddress === 'scan')
		{
			if ($this->scan())
				return TRUE;
		}
		else if ($subAddress === 'outbox')
		{
			return $this->addToOutbox();
		}
		else if ($subAddress === 'documents')
			return $this->addToDocuments();
		else
		{
			$existedShipardEmail = $this->app()->cfgItem ('wkf.shipardEmails.'.$subAddress, NULL);
			if ($existedShipardEmail !== NULL)
			{
				if ($existedShipardEmail['type'] === 'tray')
					return $this->addToTray($existedShipardEmail);
			}
		}

		return $this->addToIssues($subAddress);
	}

	private function appendEmails ($emails)
	{
		forEach ($emails as $h)
		{
			$addr = $h['address'];
			if (isset ($this->emails[$addr]))
				continue;
			$this->emails[$addr] = array ('name' => $h['name']);
			$this->coreEmails [] = $addr;
		}
	}

	protected function checkEmails ()
	{
		$this->appendEmails($this->headerFrom);
		$this->appendEmails($this->headerTo);

		$rows = $this->db()->query ('SELECT recid, valueString FROM [e10_base_properties] where [group] = %s', 'contacts',
			' AND property = %s', 'email', ' AND valueString IN %in', $this->coreEmails);

		forEach ($rows as $r)
		{
			$addr = $r['valueString'];
			$ndx = $r['recid'];

			if (isset($this->emails[$addr]))
				$this->emails[$addr]['personNdx'] = $ndx;
		}
	}

	protected function systemInfo()
	{
		$systemInfo = ['email' => []];

		$headers = $this->emailParser->getHeaders();
		forEach ($headers as $hdrIdOrig => $hdrValues)
		{
			$hdrId = Str::tolower($hdrIdOrig);
			if (!array_key_exists($hdrId, self::$emlHeadersAddr) && !in_array($hdrId, self::$emlHeadersOther))
				continue;
			$h = $this->parseHeader ($hdrValues);

			foreach ($h as $decodedHdr)
			{
				if (array_key_exists($hdrId, self::$emlHeadersAddr))
				{
					$hdrName = utils::strToUtf8($decodedHdr['name']);

					$item = ['address' => $decodedHdr['address']];
					if ($item['address'] !== $hdrName && $hdrName !== '')
						$item['name'] = $hdrName;
					$systemInfo['email'][self::$emlHeadersAddr[$hdrId]][] = $item;
				}
				elseif (in_array($hdrId, self::$emlHeadersOther))
				{
					$str = utils::strToUtf8($decodedHdr['name']);
					$systemInfo['email']['headers'][] = ['header' => $hdrId, 'value' => $str];
				}
			}
		}

		return $systemInfo;
	}

	protected function setPersons ($linkId, $emails)
	{
		$author = 0;

		forEach ($emails as $h)
		{
			$addr = $h['address'];
			if (!isset ($this->emails[$addr]))
				continue;
			if (!isset ($this->emails[$addr]['personNdx']))
				continue;

			$newLink = [
				'linkId' => $linkId,
				'srcTableId' => 'wkf.core.issues', 'srcRecId' => $this->newMsgNdx,
				'dstTableId' => 'e10.persons.persons', 'dstRecId' => $this->emails[$addr]['personNdx']
			];
			$this->db()->query ('INSERT INTO e10_base_doclinks ', $newLink);

			if ($linkId === 'wkf-issues-from' && !$author)
			{ // author was detected from sender email
				$this->db()->query (
					'UPDATE [wkf_core_issues] SET [author] = %i', $this->emails[$addr]['personNdx'],
					' WHERE ndx = %i', $this->newMsgNdx
				);
				$author = $this->emails[$addr]['personNdx'];
			}
		}
	}

	private function __decodeHeader($input)
	{
		// Remove white space between encoded-words
		$input = preg_replace('/(=\?[^?]+\?(q|b)\?[^?]*\?=)(\s)+=\?/i', '\1=?', $input);

		// For each encoded-word...
		while (preg_match('/(=\?([^?]+)\?(q|b)\?([^?]*)\?=)/i', $input, $matches))
		{
			$encoded  = $matches[1];
			$charset  = strtolower($matches[2]);
			$encoding = $matches[3];
			$text     = $matches[4];

			switch (strtolower($encoding))
			{
				case 'b':
					$text = base64_decode($text);
					break;
				case 'q':
					$text = str_replace('_', ' ', $text);
					preg_match_all('/=([a-f0-9]{2})/i', $text, $matches);
					foreach($matches[1] as $value)
						$text = str_replace('='.$value, chr(hexdec($value)), $text);
					break;
			}

			if ($charset != '' && $charset !== 'utf-8')
				$text = iconv($charset, 'utf-8', $text);

			$input = str_replace($encoded, $text, $input);
		}

		return $input;
	}

	private function parseHeader ($hstr)
	{
		if (is_string($hstr))
		{
			$headers =  mailparse_rfc822_parse_addresses ($hstr);
			forEach ($headers as &$h)
			{
				$h['name'] = $this->__decodeHeader($h['display']);
			}
			return $headers;
		}

		$headers = [];
		foreach ($hstr as $oneHeader)
		{
			$items = mailparse_rfc822_parse_addresses ($oneHeader);
			foreach ($items as $oneItem)
			{
				$i = ['address' => $oneItem['address'], 'name' => $oneItem['display']];
				$headers[] = $i;
			}
		}
		return $headers;
	}
}

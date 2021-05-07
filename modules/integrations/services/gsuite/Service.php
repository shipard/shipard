<?php

namespace integrations\services\gsuite;

use e10\utils;


/**
 * Class Service
 * @package integrations\services\gsuite
 */
class Service extends \integrations\services\core\Service
{
	function retrieveAllFiles($service)
	{
		$result = array();
		$pageToken = NULL;

		do {
			try {
				$parameters = array();
				if ($pageToken) {
					$parameters['pageToken'] = $pageToken;
				}
				$files = $service->files->listFiles($parameters);

				$result = array_merge($result, $files->getItems());
				$pageToken = $files->getNextPageToken();
			}
			catch (Exception $e)
			{
				//print "An error occurred: " . $e->getMessage();
				$pageToken = NULL;
			}
		} while ($pageToken);
		return $result;
	}

	function downloadOneFile ($cloudService, $fileInfo)
	{
		if ($fileInfo['fileSize'] > 10*1024*1024)
			return '';

		$fileId = $fileInfo['fileId'];
		$fullFileName = __APP_DIR__.'/tmp/'.utils::createToken(8).'-'.utils::safeChars($fileInfo['fileName']);

		$response = $cloudService->files->get($fileId, ['alt' => 'media']);
		$content = $response->getBody()->getContents();

		file_put_contents($fullFileName, $content);

		return $fullFileName;
	}

	function downloadFiles()
	{
		$tableDF = $this->app()->table('integrations.core.downloadFiles');
		$countFiles = 0;

		putenv('GOOGLE_APPLICATION_CREDENTIALS='.$this->authKeyFileName);
		try
		{
			$client = new \Google_Client();
			$client->useApplicationDefaultCredentials();
			$client->addScope(\Google_Service_Drive::DRIVE);

			$service = new \Google_Service_Drive($client);

			$optParams = [
				'pageSize' => 10,
				'fields' => 'nextPageToken, files(id, name, createdTime, size, imageMediaMetadata, lastModifyingUser, parents, contentHints)',
				'q' => "createdTime > '{$this->downloadFilesLimitDateTime}'"
			];

			//'fields' => "nextPageToken, files(contentHints/thumbnail,fileExtension,iconLink,id,name,size,thumbnailLink,webContentLink,webViewLink,mimeType,parents)",

			$files = $service->files->listFiles($optParams);
		}
		catch (Exception $e)
		{
			$this->deleteAuthKey();
			return;
		}

		foreach ($files->getFiles() as $file)
		{
			$downloadFileInfo = [
				'service' => $this->taskRecData['service'], 'task' => $this->taskRecData['ndx'],
				'fileId' => $file->getId(), 'fileName' => $file->getName(), 'fileSize' => $file->getSize(),
				'fileCreatedDateTime' => $file->getCreatedTime(),
				//'imageMediaMetadata' => $file->getImageMediaMetadata(),
				//'user' => , 'parents' => $file->getParents(), 'contentHints' => $file->getContentHints(),
			];

			$userInfo = $file->getLastModifyingUser();
			if ($userInfo && isset($userInfo['emailAddress']))
				$downloadFileInfo['userEmail'] = $userInfo['emailAddress'];

			$imageMediaMetadata = $file->getImageMediaMetadata();
			if ($imageMediaMetadata)
			{
				if (isset($imageMediaMetadata['location']))
				{
					$downloadFileInfo['lat'] = $imageMediaMetadata['location']['latitude'];
					$downloadFileInfo['lon'] = $imageMediaMetadata['location']['longitude'];
					$downloadFileInfo['locState'] = 1;
				}

				if (isset($imageMediaMetadata['width']))
					$downloadFileInfo['i1'] = $imageMediaMetadata['width'];
				if (isset($imageMediaMetadata['height']))
					$downloadFileInfo['i2'] = $imageMediaMetadata['height'];
			}

			if ($tableDF->addFileToFront($downloadFileInfo))
				$countFiles++;

			//echo json_encode($downloadFileInfo)."\n";
		}

		if ($countFiles)
		{
			$this->saveDownloadFilesInfo();
		}

		$this->downloadFilesQueue($service);
	}

	function runTask ($taskRecData)
	{
		parent::runTask($taskRecData);

		$this->saveAuthKey();

		switch ($this->taskTypeId)
		{
			case 'download-files': $this->downloadFiles(); break;
		}

		$this->deleteAuthKey();
	}
}
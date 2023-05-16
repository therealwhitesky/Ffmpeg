<?php

namespace Nulumia\Ffmpeg\Job;

use XF\Job\AbstractJob;

class CompressVideo extends AbstractJob
{
	protected $defaultData = [
		'start' => 0,
		'batch' => 100
	];

	public function run($maxRunTime)
	{
		$startTime = microtime(true);

		$db = $this->app->db();
		$em = $this->app->em();

		$ids = $db->fetchAllColumn($db->limit(
			"
				SELECT data_id
				FROM xf_attachment_data
				WHERE data_id > ?
				ORDER BY data_id
			", $this->data['batch']
		), $this->data['start']);
		if (!$ids)
		{
			return $this->complete();
		}

		$done = 0;

		$cfrScaler = false;
		$cfrScalerM = .7;

		foreach ($ids AS $id)
		{
			$this->data['start'] = $id;

			/** @var \XF\Entity\AttachmentData $attachData */
			$attachData = $em->find('XF:AttachmentData', $id);

			if ($attachData->isVideo() && $attachData->getExtension() && !$attachData->nl_ffmpeg_compress_date && strpos($attachData->filename, '.') !== false)
			{
				$dataPath = $attachData->getSourceAttachmentDataPath();
				
				if (!$dataPath)
				{
					\XF::logError("Could not fetch data path for video with attachment id: '$attachData->data_id', skipping");
					self::setCompressError($attachData, true, true);
					$done++;
					continue;
				}
				
				// Error handling
				if ($attachData->file_size == 0)
				{
					// May have been a flawed compression previously, try restoring filesize if file is valid video
					if (self::checkIsValidVideo($dataPath))
					{
						if (!self::handleErrorVideo($attachData, $dataPath))
						{
							\XF::logError("An attachment (id: '$attachData->data_id') video is missing file size but could not fetch filesize from the video file");
							self::setCompressError($attachData, true, true);
							$done++;
							continue;
						}
					}
					else
					{
						\XF::logError("An attachment (id: '$attachData->data_id') video is missing file size but could not fetch filesize from the video file");
						self::setCompressError($attachData, true, true);
						$done++;
						continue;
					}
				}
				
				// FFMpeg settings
				$crf = 28;
				// Age conditioner
				if ($cfrScaler)
				{
					$diff = \XF::$time - $attachData->upload_date;
					$years = $diff / 31556952;
					$cfrInc = $years * $cfrScalerM;

					$crf = $crf + $cfrInc;
				}
				
				$dataPath = $attachData->getSourceAttachmentDataPath();
				$extension = $attachData->getExtension();

				$dataPathNoExtension = substr($dataPath, 0, strpos($dataPath, '.'));
				
				//rename($dataPath, $dataPathNoExtension .= '-temp.' . $extension);
				//-c:v libx264 -crf 30 -preset sl -vf "scale='if(gt(iw\,ih),1280,-2)':h='if(gt(iw\,ih),-2,1280)'" 
				shell_exec("ffmpeg -y -i " . $dataPath . " -loglevel error -vcodec libx265 -preset fast -crf " . $crf . " -b:a 128k -vf \"select='eq(n,0)+if(gt(t-prev_selected_t,1/30.50),1,0)', scale='if(gte(iw,ih),min(1920,iw),-2):if(lt(iw,ih),min(1920,ih),-2)'\" " . $dataPathNoExtension . '-temp.' . $extension);
				
				$newFilePath = $dataPathNoExtension . '-temp.' . $extension;
				
				// Check new file exists
				if (!file_exists($newFilePath) || !filesize($newFilePath))
				{
					\XF::logError("There was an error reading the compressed temp video created with FFMpeg");
					self::setCompressError($attachData, true, true);
					$done++;
					continue;
				}
				//print "New file path is " . $newFilePath . " and size is " . filesize($newFilePath) . " and the attachData size is " . $attachData->file_size;
				
				$newSize = filesize($newFilePath);
				
				// Check new file sizes and keep compressing if needed
				while (($newSize / $attachData->file_size) > .5)
				{
					$ratio = $newSize / $attachData->file_size;
					\XF::logError("New size is '$newSize' on '$newFilePath' and file size is '$attachData->file_size' and ratio is '$ratio'");
					$crf++;
					shell_exec("ffmpeg -y -i " . $dataPath . " -loglevel error -vcodec libx265 -preset fast -crf " . $crf . " -b:a 128k -vf \"select='eq(n,0)+if(gt(t-prev_selected_t,1/30.50),1,0)', scale='if(gte(iw,ih),min(1920,iw),-2):if(lt(iw,ih),min(1920,ih),-2)'\" " . $dataPathNoExtension . '-temp.' . $extension);
					clearstatcache(true, $newFilePath);
					$newSize = filesize($newFilePath);
					
				}
				
				// Check if is valid video
				if (!self::checkIsValidVideo($newFilePath))
				{
					\XF::logError("A temp video was created for attachment ID: '$attachData->data_id' but could not validate the file as a valid video");
					$attachData->nl_ffmpeg_compress_error = 1;
					
					// delete temp file
					unlink($newFilePath);
					$done++;
					continue;
				}
				
				// Final steps
				
				rename($dataPath, $dataPathNoExtension . '-old.' . $extension);
				rename($newFilePath, $dataPath);
				
				// Log info
				$attachData->nl_ffmpeg_original_file_size = $attachData->file_size;
				$attachData->file_size = $newSize;
				$attachData->nl_ffmpeg_compress_date = \XF::$time;
				
				if ($attachData->nl_ffmpeg_compress_error = 1)
				{
					self::setCompressError($attachData, false, false);
				}
				$attachData->save();
				
				unlink($dataPathNoExtension . '-old.' . $extension);
			}

			$done++;

			if (microtime(true) - $startTime >= $maxRunTime)
			{
				break;
			}
		}

		$this->data['batch'] = $this->calculateOptimalBatch($this->data['batch'], $done, $startTime, $maxRunTime, 1000);

		return $this->resume();
	}
	
	public function handleErrorVideo(\XF\Entity\AttachmentData $attachData)
	{
		if ($attachVideo->file_size == 0)
		{	
			if (file_exists($dataPath) && filesize($dataPath))
			{
				$attachData->file_size = filesize($dataPath);
				$attachData->save();
			}
			return true;
		}
		
		return false;
	}
	
	public function checkIsValidVideo($path)
	{
		$mime = mime_content_type($path);
		
		if (strstr($mime, "video/"))
		{
			return true;
		}
		
		return false;
	}
	
	public function setCompressError(\XF\Entity\AttachmentData $attachData, bool $error, $save = true)
	{
		if ($error)
		{
			$attachData->nl_ffmpeg_compress_error = 1;
		}
		else
		{
			$attachData->nl_ffmpeg_compress_error = 0;
		}
		
		if ($save)
		{
			$attachData->save();
		}
	}

	public function getStatusMessage()
	{
		$actionPhrase = \XF::phrase('nulumia_ffmpeg_compressing');
		$typePhrase = \XF::phrase('nulumia_ffmpeg_attachment_videos');
		return sprintf('%s... %s (%s)', $actionPhrase, $typePhrase, $this->data['start']);
	}

	public function canCancel()
	{
		return true;
	}

	public function canTriggerByChoice()
	{
		return true;
	}
}
<?php

namespace Smartling\Base;

use Smartling\DbAl\WordpressContentEntities\EntityAbstract;
use Smartling\Exception\SmartlingWpDataIntegrityException;
use Smartling\Helpers\AttachmentHelper;
use Smartling\Helpers\ContentSerializationHelper;
use Smartling\Helpers\WordpressContentTypeHelper;
use Smartling\Helpers\XmlEncoder;
use Smartling\Submissions\SubmissionEntity;

/**
 * Class SmartlingCoreTrait
 * @package Smartling\Base
 */
trait SmartlingCoreTrait
{
    use SmartlingCoreUploadTrait;
    use SmartlingCoreDownloadTrait;

    protected function fastSendForTranslation($contentType, $sourceBlog, $sourceId, $targetBlog)
    {
        $relatedSubmission = $this->prepareSubmissionEntity(
            $contentType,
            $sourceBlog,
            $sourceId,
            $targetBlog
        );
        if (0 === (int)$relatedSubmission->getId()) {
            $relatedSubmission = $this->getSubmissionManager()->storeEntity($relatedSubmission);
        }
        $submission_id = $relatedSubmission->getId();
        $this->sendForTranslationBySubmission($relatedSubmission);
        $lst = $this->getSubmissionManager()->getEntityById($submission_id);
        $relatedSubmission = reset($lst);

        return $relatedSubmission;
    }

    /**
     * Sends Entity for translation and returns ID of linked entity in target blog
     *
     * @param string $contentType
     * @param int    $sourceBlog
     * @param int    $sourceId
     * @param int    $targetBlog
     *
     * @return int
     */
    private function translateAndGetTargetId($contentType, $sourceBlog, $sourceId, $targetBlog)
    {
        $submission = $this->fastSendForTranslation($contentType, $sourceBlog, $sourceId, $targetBlog);

        return (int)$submission->getTargetId();
    }

    /**
     * @param string   $contentType
     * @param int      $sourceBlog
     * @param mixed    $sourceEntity
     * @param int      $targetBlog
     * @param int|null $targetEntity
     *
     * @return SubmissionEntity
     */
    private function prepareSubmissionEntity($contentType, $sourceBlog, $sourceEntity, $targetBlog, $targetEntity = null)
    {
        return $this->getSubmissionManager()->getSubmissionEntity(
            $contentType,
            $sourceBlog,
            $sourceEntity,
            $targetBlog,
            $this->getMultilangProxy(),
            $targetEntity
        );
    }

    /**
     * @param SubmissionEntity $submission
     */
    private function prepareFieldProcessorValues(SubmissionEntity $submission)
    {
        ContentSerializationHelper::prepareFieldProcessorValues($this->getSettingsManager(), $submission);
    }

    /**
     * Prepares a duplicate of source content for target site and links them.
     * To be used JUST BEFORE SENDING to Smartling
     *
     * @param SubmissionEntity $submission
     * @param bool             $forceClone
     *
     * @return SubmissionEntity
     */
    protected function prepareTargetEntity(SubmissionEntity $submission, $forceClone = false)
    {
        $update = 0 !== (int)$submission->getTargetId();

        if (WordpressContentTypeHelper::CONTENT_TYPE_MEDIA_ATTACHMENT === $submission->getContentType()) {
            $fileData = $this->getAttachmentFileInfoBySubmission($submission);
            $sourceFileFsPath = $fileData['source_path_prefix'] . DIRECTORY_SEPARATOR . $fileData['relative_path'];
            $targetFileFsPath = $fileData['target_path_prefix'] . DIRECTORY_SEPARATOR . $fileData['relative_path'];
            $mediaCloneResult = AttachmentHelper::cloneFile($sourceFileFsPath, $targetFileFsPath, true);
            $result = AttachmentHelper::CODE_SUCCESS === $mediaCloneResult;
            if (AttachmentHelper::CODE_SUCCESS !== $mediaCloneResult) {
                $message = vsprintf('Error %s happened while working with attachment.', [$mediaCloneResult]);
                $this->getLogger()->error($message);
            }
        }

        $this->regenerateTargetThumbnailsBySubmission($submission);

        if (true === $update && false === $forceClone) {
            return $submission;
        }

        $this->getLogger()->debug(
            vsprintf(
                'Cloning target entity for submission = \'%s\' for locale = \'%s\' in blog =\'%s\'.',
                [
                    $submission->getId(),
                    $submission->getTargetLocale(),
                    $submission->getTargetBlogId(),
                ]
            )
        );

        $originalContent = $this->getContentHelper()->readSourceContent($submission);

        $this->prepareFieldProcessorValues($submission);
        $sourceData = $this->readSourceContentWithMetadata($submission);
        $original = XmlEncoder::xmlDecode(XmlEncoder::xmlEncode($sourceData));

        if (false === $update) {
            $targetContent = clone $originalContent;
            $targetContent->cleanFields();
        } else {
            $targetContent = $this->getContentHelper()->readTargetContent($submission);
        }

        unset ($original['entity']['ID'], $original['entity']['term_id'], $original['entity']['id']);

        foreach ($original['entity'] as $k => $v) {
            $targetContent->{$k} = $v;
        }

        if (false === $forceClone) {
            $targetContent->translationDrafted();
        }

        $targetContent = $this->getContentHelper()->writeTargetContent($submission, $targetContent);

        $this->getLogger()
            ->debug(
                vsprintf(
                    'Created target entity for submission = \'%s\' for locale = \'%s\' in blog =\'%s\', id = \'%s\'.',
                    [
                        $submission->getId(),
                        $submission->getTargetLocale(),
                        $submission->getTargetBlogId(),
                        $targetContent->getPK(),
                    ]
                )
            );

        if (array_key_exists('meta', $original) && is_array($original['meta']) && 0 < count($original['meta'])) {
            $this->getContentHelper()->writeTargetMetadata($submission, $original['meta']);
        }

        if (false === $update) {
            $submission->setTargetId($targetContent->getPK());
            $submission = $this->getSubmissionManager()->storeEntity($submission);
        }
        $result = $this->getMultilangProxy()->linkObjects($submission);

        return $submission;
    }

    /**
     * @param EntityAbstract $entity
     * @param array          $properties
     */
    private function setValues(EntityAbstract $entity, array $properties)
    {
        foreach ($properties as $propertyName => $propertyValue) {
            if ($entity->{$propertyName} != $propertyValue) {
                $message = vsprintf(
                    'Replacing field %s with value %s to value %s',
                    [
                        $propertyName,
                        json_encode($entity->{$propertyName}, JSON_UNESCAPED_UNICODE),
                        json_encode($propertyValue, JSON_UNESCAPED_UNICODE),
                    ]
                );
                $this->getLogger()->debug($message);
                $entity->{$propertyName} = $propertyValue;
            }
        }
    }

    /**
     * @param int $siteId
     *
     * @return array
     */
    private function getUploadDirForSite($siteId)
    {
        $needSiteChange = (int)$siteId !== $this->getSiteHelper()->getCurrentBlogId();
        if ($needSiteChange) {
            $this->getSiteHelper()->switchBlogId((int)$siteId);
        }
        $data = wp_upload_dir();
        if ($needSiteChange) {
            $this->getSiteHelper()->restoreBlogId();
        }

        return $data;
    }

    private function getUploadPathForSite($siteId)
    {
        $needSiteChange = (int)$siteId !== $this->getSiteHelper()->getCurrentBlogId();
        if ($needSiteChange) {
            $this->getSiteHelper()->switchBlogId((int)$siteId);
        }
        $prefix = $this->getUploadDirForSite($siteId);
        $data = str_replace($prefix['subdir'], '', parse_url($prefix['url'], PHP_URL_PATH));
        if ($needSiteChange) {
            $this->getSiteHelper()->restoreBlogId();
        }

        return $data;
    }

    /**
     * Collects and returns info to copy attachment media
     *
     * @param SubmissionEntity $submission
     *
     * @return array
     * @throws SmartlingWpDataIntegrityException
     */
    private function getAttachmentFileInfoBySubmission(SubmissionEntity $submission)
    {
        $info = $this->getContentHelper()->readSourceContent($submission);
        $sourceSiteUploadInfo = $this->getUploadDirForSite($submission->getSourceBlogId());
        $targetSiteUploadInfo = $this->getUploadDirForSite($submission->getTargetBlogId());
        $sourceMetadata = $this->getContentHelper()->readSourceMetadata($submission);
        if (array_key_exists('_wp_attached_file', $sourceMetadata) && is_array($sourceMetadata['_wp_attached_file'])) {
            $relativePath = reset($sourceMetadata['_wp_attached_file']);
            $result = [
                'uri'                => $info->guid,
                'relative_path'      => $relativePath,
                'source_path_prefix' => $sourceSiteUploadInfo['basedir'],
                'target_path_prefix' => $targetSiteUploadInfo['basedir'],
                'base_url_target'    => $targetSiteUploadInfo['baseurl'],
                'filename'           => vsprintf('%s.%s', [
                    pathinfo($relativePath, PATHINFO_FILENAME),
                    pathinfo($relativePath, PATHINFO_EXTENSION),
                ]),
            ];

            return $result;
        }
        throw new SmartlingWpDataIntegrityException(
            vsprintf(
                'Seems like Wordpress has mess in the database, metadata (key=\'%s\') is missing for submission=\'%s\' (content-type=\'%s\', source blog=\'%s\', id=\'%s\')',
                [
                    '_wp_attached_file',
                    $submission->getId(),
                    $submission->getContentType(),
                    $submission->getSourceBlogId(),
                    $submission->getSourceId(),
                ]
            )
        );
    }

}
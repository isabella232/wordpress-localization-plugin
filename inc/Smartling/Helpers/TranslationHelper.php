<?php

namespace Smartling\Helpers;


use Psr\Log\LoggerInterface;
use Smartling\Base\ExportedAPI;
use Smartling\DbAl\LocalizationPluginProxyInterface;
use Smartling\Exception\SmartlingDataReadException;
use Smartling\MonologWrapper\MonologWrapper;
use Smartling\Submissions\SubmissionEntity;
use Smartling\Submissions\SubmissionManager;

class TranslationHelper
{

    /**
     * @var LocalizationPluginProxyInterface
     */
    private $mutilangProxy;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var SubmissionManager
     */
    private $submissionManager;

    /**
     * TranslationHelper constructor.
     */
    public function __construct() {
        $this->logger = MonologWrapper::getLogger(get_called_class());
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @return SubmissionManager
     */
    public function getSubmissionManager()
    {
        return $this->submissionManager;
    }

    /**
     * @param SubmissionManager $submissionManager
     */
    public function setSubmissionManager($submissionManager)
    {
        $this->submissionManager = $submissionManager;
    }

    /**
     * @return LocalizationPluginProxyInterface
     */
    public function getMutilangProxy()
    {
        return $this->mutilangProxy;
    }

    /**
     * @param LocalizationPluginProxyInterface $mutilangProxy
     */
    public function setMutilangProxy($mutilangProxy)
    {
        $this->mutilangProxy = $mutilangProxy;
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
    public function prepareSubmissionEntity($contentType, $sourceBlog, $sourceEntity, $targetBlog, $targetEntity = null)
    {
        return $this->getSubmissionManager()->getSubmissionEntity(
            $contentType,
            $sourceBlog,
            $sourceEntity,
            $targetBlog,
            $this->getMutilangProxy(),
            $targetEntity
        );
    }

    /**
     * @param string $contentType
     * @param int    $sourceBlog
     * @param int    $sourceId
     * @param int    $targetBlog
     * @param bool   $clone
     *
     * @return mixed
     */
    public function prepareSubmission($contentType, $sourceBlog, $sourceId, $targetBlog, $clone = false)
    {
        if (0 == $sourceId) {
            throw new \InvalidArgumentException('Source id cannot be 0.');
        }
        $submission = $this->prepareSubmissionEntity(
            $contentType,
            $sourceBlog,
            $sourceId,
            $targetBlog
        );

        if (0 === (int)$submission->getId()) {
            if (true === $clone) {
                $submission->setIsCloned(1);
            }
            $submission = $this->getSubmissionManager()->storeEntity($submission);
        }

        return $this->reloadSubmission($submission);
    }

    /**
     * @param SubmissionEntity $submission
     *
     * @return mixed
     * @throws \Smartling\Exception\SmartlingDataReadException
     */
    public function reloadSubmission(SubmissionEntity $submission)
    {
        $submissionsList = $this->getSubmissionManager()->getEntityById($submission->getId());
        if (is_array($submissionsList)) {
            return ArrayHelper::first($submissionsList);
        }
        $message = vsprintf(
            'Error while reloading submission. Nothing returned from database. Original Submission: %s',
            [var_export($submission->toArray(false), true),]
        );
        throw new SmartlingDataReadException($message);
    }

    /**
     * @param string $contentType
     * @param int    $sourceBlog
     * @param int    $sourceId
     * @param int    $targetBlog
     * @param bool   $clone
     *
     * @return SubmissionEntity
     */
    public function tryPrepareRelatedContent($contentType, $sourceBlog, $sourceId, $targetBlog, $clone = false, $batchUid = '')
    {
        $relatedSubmission = $this->prepareSubmission($contentType, $sourceBlog, $sourceId, $targetBlog, $clone);

        /**
         * @var SubmissionEntity $relatedSubmission
         */
        if (0 !== $sourceId && 0 === $relatedSubmission->getTargetId() &&
            SubmissionEntity::SUBMISSION_STATUS_FAILED !== $relatedSubmission->getStatus()
        ) {
            $message = vsprintf(
                'Trying to create corresponding translation placeholder for submission=%s for contentType=%s sourceBlog=%s sourceId=%s, targetBlog=%s',
                [
                    $relatedSubmission->getId(),
                    $contentType,
                    $sourceBlog,
                    $sourceId,
                    $targetBlog,
                ]
            );

            $relatedSubmission->setBatchUid($batchUid);
            $serialized = $relatedSubmission->toArray(false);
            if (null === $serialized['file_uri']) {
                $relatedSubmission->getFileUri();
            }
            $relatedSubmission = $this->getSubmissionManager()->storeEntity($relatedSubmission);
            // try to create target entity
            $relatedSubmission = apply_filters(ExportedAPI::FILTER_SMARTLING_PREPARE_TARGET_CONTENT, $relatedSubmission);

            // add to upload queue
            $relatedSubmission->setStatus(SubmissionEntity::SUBMISSION_STATUS_NEW);
            $relatedSubmission = $this->getSubmissionManager()->storeEntity($relatedSubmission);
        } else {
            $message = vsprintf(
                'Skipping creation of translation placeholder for submission=%s, locale=%s.',
                [
                    $relatedSubmission->getId(),
                    $relatedSubmission->getTargetLocale(),
                ]
            );
        }

        $this->getLogger()->debug($message);

        return $this->reloadSubmission($relatedSubmission);
    }
}
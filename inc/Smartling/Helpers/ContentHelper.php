<?php

namespace Smartling\Helpers;

use Exception;
use Smartling\DbAl\WordpressContentEntities\EntityAbstract;
use Smartling\DbAl\WordpressContentEntities\PostEntityStd;
use Smartling\DbAl\WordpressContentEntities\TaxonomyEntityStd;
use Smartling\DbAl\WordpressContentEntities\VirtualEntityAbstract;
use Smartling\Exception\EntityNotFoundException;
use Smartling\MonologWrapper\MonologWrapper;
use Smartling\Processors\ContentEntitiesIOFactory;
use Smartling\Submissions\SubmissionEntity;
use Smartling\Vendor\Psr\Log\LoggerInterface;

/**
 * A Small wrapper to read/write Registered entities and metadata by submission
 * Class ContentHelper
 * @package Smartling\Helpers
 */
class ContentHelper
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ContentEntitiesIOFactory
     */
    private $ioFactory;

    /**
     * @var SiteHelper
     */
    private $siteHelper;

    /**
     * @var bool
     */
    private $needBlogSwitch;
    private $externalBlogSwitchFrames = [];

    /**
     * @return RuntimeCacheHelper
     */
    private function getRuntimeCache()
    {
        return RuntimeCacheHelper::getInstance();
    }

    /**
     * @return ContentEntitiesIOFactory
     */
    public function getIoFactory()
    {
        return $this->ioFactory;
    }

    /**
     * @param ContentEntitiesIOFactory $ioFactory
     */
    public function setIoFactory($ioFactory)
    {
        $this->ioFactory = $ioFactory;
    }

    /**
     * @return SiteHelper
     */
    public function getSiteHelper()
    {
        return $this->siteHelper;
    }

    /**
     * @param SiteHelper $siteHelper
     */
    public function setSiteHelper($siteHelper)
    {
        $this->siteHelper = $siteHelper;
    }

    /**
     * @return boolean
     */
    public function isNeedBlogSwitch()
    {
        return $this->needBlogSwitch;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    public function __construct()
    {
        $this->logger = MonologWrapper::getLogger(get_called_class());
    }

    /**
     * @param boolean $needBlogSwitch
     */
    public function setNeedBlogSwitch($needBlogSwitch)
    {
        $this->needBlogSwitch = $needBlogSwitch;
    }

    public function ensureBlog($blogId)
    {
        $this->setNeedBlogSwitch($this->getSiteHelper()->getCurrentBlogId() !== $blogId);

        if ($this->isNeedBlogSwitch()) {
            $this->externalBlogSwitchFrames = [];
            add_action('switch_blog', [$this, 'logSwitchBlog']);
            $this->getSiteHelper()->switchBlogId($blogId);
        }
    }

    public function logSwitchBlog() {
        $backtrace = debug_backtrace();
        foreach ($backtrace as $index => $frame) {
            if (array_key_exists('function', $frame) && array_key_exists('args', $frame)) {
                $args = $frame['args'];
                if ($frame['function'] === 'do_action' && $args[0] === 'switch_blog' && !$this->isConnectorSwitch($backtrace)) {
                    $this->getLogger()->debug("Unexpected blog switch detected: " . json_encode($backtrace));
                    if (array_key_exists($index + 1, $backtrace)) {
                        $this->externalBlogSwitchFrames[] = $backtrace[$index + 1];
                    } else {
                        $this->externalBlogSwitchFrames[] = $frame;
                    }
                    break;
                }
            }
        }
    }

    /**
     * @param array $backtrace
     * @return bool
     */
    private function isConnectorSwitch(array $backtrace) {
        foreach ($backtrace as $frame) {
            if (array_key_exists('class', $frame) && array_key_exists('function', $frame) &&
                $frame['class'] === SiteHelper::class && in_array($frame['function'], ['switchBlogId', 'restoreBlogId'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $frame backtrace frame
     * @return string
     */
    private function getSwitchBlogString(array $frame) {
        $template = "Switched to blog %d in %s:%d";
        $blog = 0;
        $file = 'Unknown';
        $line = 0;
        if (array_key_exists('args', $frame) && is_array($frame['args']) && array_key_exists(1, $frame['args'])) {
            $blog = $frame['args'][1];
        }
        if (array_key_exists('file', $frame)) {
            $file = $frame['file'];
        }
        if (array_key_exists('line', $frame)) {
            $line = $frame['line'];
        }
        return sprintf($template, $blog, $file, $line);
    }

    public function ensureSourceBlogId(SubmissionEntity $submission)
    {
        $this->ensureBlog($submission->getSourceBlogId());
    }

    public function ensureTargetBlogId(SubmissionEntity $submission)
    {
        $this->ensureBlog($submission->getTargetBlogId());
    }

    public function ensureRestoredBlogId()
    {
        if ($this->isNeedBlogSwitch()) {
            remove_action('switch_blog', [$this, 'logSwitchBlog']);
            $this->getSiteHelper()->restoreBlogId();
            $this->setNeedBlogSwitch(false);
        }
    }

    /**
     * @param string $contentType
     *
     * @return EntityAbstract
     */
    private function getWrapper($contentType)
    {
        $return = clone $this->getIoFactory()->getHandler($contentType);
        if (!$return instanceof EntityAbstract) {
            throw new \RuntimeException("Handler for {$contentType} expected to be EntityAbstract, factory returned " . get_class($return));
        }

        return $return;
    }

    /**
     * @param SubmissionEntity $submission
     *
     * @return EntityAbstract
     */
    public function readSourceContent(SubmissionEntity $submission)
    {
        if (false === ($cached = $this->getRuntimeCache()->get($submission->getId(), 'sourceContent'))) {
            $wrapper = $this->getWrapper($submission->getContentType());
            $this->ensureSourceBlogId($submission);
            $sourceContent = $wrapper->get($submission->getSourceId());
            $this->ensureRestoredBlogId();
            $clone = clone $sourceContent;
            $this->getRuntimeCache()->set($submission->getId(), $sourceContent, 'sourceContent');
        } else {
            $clone = clone $cached;
        }
        return $clone;
    }

    /**
     * @param SubmissionEntity $submission
     *
     * @return EntityAbstract
     */
    public function readTargetContent(SubmissionEntity $submission)
    {
        $wrapper = $this->getWrapper($submission->getContentType());
        $this->ensureTargetBlogId($submission);
        $targetContent = $wrapper->get($submission->getTargetId());
        $this->ensureRestoredBlogId();

        return $targetContent;
    }

    /**
     * @param SubmissionEntity $submission
     *
     * @return array
     */
    public function readSourceMetadata(SubmissionEntity $submission)
    {
        if (false === ($cached = $this->getRuntimeCache()->get($submission->getId(), 'sourceMeta'))) {
            $metadata = [];
            $wrapper = $this->getWrapper($submission->getContentType());
            $this->ensureSourceBlogId($submission);
            $sourceContent = $wrapper->get($submission->getSourceId());
            if ($sourceContent instanceof EntityAbstract) {
                $metadata = $sourceContent->getMetadata();
            }
            $this->ensureRestoredBlogId();

            $clone = $metadata;
            $this->getRuntimeCache()->set($submission->getId(), $metadata, 'sourceMeta');
        } else {
            $clone = $cached;
        }
        return $clone;
    }

    /**
     * @param SubmissionEntity $submission
     *
     * @return array
     */
    public function readTargetMetadata(SubmissionEntity $submission)
    {
        $metadata = [];
        $wrapper = $this->getWrapper($submission->getContentType());
        $this->ensureTargetBlogId($submission);
        $targetContent = $wrapper->get($submission->getTargetId());
        if ($targetContent instanceof EntityAbstract) {
            $metadata = $targetContent->getMetadata();
        }
        $this->ensureRestoredBlogId();

        return $metadata;
    }

    /**
     * @param SubmissionEntity $submission
     * @param EntityAbstract   $entity
     *
     * @return EntityAbstract
     */
    public function writeTargetContent(SubmissionEntity $submission, EntityAbstract $entity)
    {
        $wrapper = $this->getWrapper($submission->getContentType());

        $this->ensureTargetBlogId($submission);

        $result = $wrapper->set($entity);

        if (is_int($result) && 0 < $result) {
            try {
                $result = $wrapper->get($result);
            } catch (EntityNotFoundException $e) {
                if (count($this->externalBlogSwitchFrames) > 0) {
                    $message = "Unable to get target content: WordPress blog was switched outside Smartling connector plugin. Detected blog change frames follow:\n";
                    foreach ($this->externalBlogSwitchFrames as $frame) {
                        $message .= $this->getSwitchBlogString($frame) . "\n";
                    }
                    throw new EntityNotFoundException($message, $e->getCode(), $e);
                }
            }
        }

        try {
            $this->ensureRestoredBlogId();
        } catch (\LogicException $e) {
            $message = $e->getMessage();
            if (count($this->externalBlogSwitchFrames) > 0) {
                $message = "Failed to restore blog. Detected blog change frames follow:\n";
                foreach ($this->externalBlogSwitchFrames as $frame) {
                    $message .= $this->getSwitchBlogString($frame) . "\n";
                }
            }
            throw new \LogicException($message, $e->getCode(), $e);
        }

        return $result;
    }

    /**
     * @param SubmissionEntity $submission
     * @param array $metadata
     */
    public function writeTargetMetadata(SubmissionEntity $submission, array $metadata)
    {
        $wrapper = $this->getWrapper($submission->getContentType());
        $this->ensureTargetBlogId($submission);
        $targetEntity = $wrapper->get($submission->getTargetId());
        if ($targetEntity instanceof EntityAbstract) {
            foreach ($metadata as $key => $value) {
                $targetEntity->setMetaTag($key, $value);
            }
        }
        $this->ensureRestoredBlogId();
    }

    public function removeTargetMetadata(SubmissionEntity $submission)
    {
        $existing_meta = $this->readTargetMetadata($submission);
        $this->getLogger()->debug(
            vsprintf('Removing ALL metadata for target content for submission %s', [$submission->getId()])
        );

        $wrapper = $this->getWrapper($submission->getContentType());
        $wpMetaFunction = null;

        $this->ensureTargetBlogId($submission);

        if ($wrapper instanceof PostEntityStd) {
            $wpMetaFunction = 'delete_post_meta';
        } elseif ($wrapper instanceof TaxonomyEntityStd) {
            $wpMetaFunction = 'delete_term_meta';
        } elseif (!$wrapper instanceof VirtualEntityAbstract) {
            $msgTemplate = 'Unknown content-type. Expected %s to be successor of one of: %s';
            $this->getLogger()->warning(
                vsprintf($msgTemplate,
                         [get_class($wrapper),
                          implode(',', ['PostEntity', 'TaxonomyEntityAbstract', 'VirtualEntityAbstract']),
                         ]
                )
            );
        }

        $object_id = $submission->getTargetId();
        if (null !== $wpMetaFunction) {
            try {
                foreach ($existing_meta as $key => $value) {
                    $result = $wpMetaFunction($object_id, $key);
                    $msg_template = 'Removing metadata key=\'%s\' for \'%s\' id=\'%s\' (submission = \'%s\' ) finished ' .
                                    (true === $result ? 'successfully' : 'with error');
                    $msg = vsprintf($msg_template, [$key, $submission->getContentType(), $submission->getTargetId(),
                                                    $submission->getId()]);
                    $this->getLogger()->debug($msg);
                }
            } catch (Exception $e) {
                $msg = vsprintf('Error while deleting target metadata for submission id=\'%s\'. Message: %s',
                                [
                                    $submission->getId(),
                                    $e->getMessage(),
                                ]);
                $this->getLogger()->warning($msg);
            }
        }

        $this->ensureRestoredBlogId();
    }

    /**
     * @param int $blogId
     * @param string $contentType
     * @param int $contentId
     * @return bool
     */
    public function checkEntityExists($blogId, $contentType, $contentId)
    {
        $needSiteSwitch = (int)$blogId !== $this->getSiteHelper()->getCurrentBlogId();
        $result = false;

        if ($needSiteSwitch) {
            $this->getSiteHelper()->switchBlogId((int)$blogId);
        }

        try {
            if (($this->getIoFactory()->getMapper($contentType)->get($contentId)) instanceof EntityAbstract) {
                $result = true;
            }
        } catch (Exception $e) {
        }

        if ($needSiteSwitch) {
            $this->getSiteHelper()->restoreBlogId();
        }
        return $result;
    }
}

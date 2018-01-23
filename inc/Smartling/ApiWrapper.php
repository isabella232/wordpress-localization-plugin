<?php

namespace Smartling;

use DateTime;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Smartling\AuthApi\AuthTokenProvider;
use Smartling\Batch\BatchApi;
use Smartling\Batch\Params\CreateBatchParameters;
use Smartling\Exception\SmartligFileDownloadException;
use Smartling\Exception\SmartlingDbException;
use Smartling\Exception\SmartlingFileDownloadException;
use Smartling\Exception\SmartlingFileUploadException;
use Smartling\Exception\SmartlingNetworkException;
use Smartling\Exceptions\SmartlingApiException;
use Smartling\File\FileApi;
use Smartling\File\Params\DownloadFileParameters;
use Smartling\File\Params\UploadFileParameters;
use Smartling\Helpers\ArrayHelper;
use Smartling\Helpers\FileHelper;
use Smartling\Helpers\LogContextMixinHelper;
use Smartling\Helpers\RuntimeCacheHelper;
use Smartling\Jobs\JobsApi;
use Smartling\Jobs\Params\AddFileToJobParameters;
use Smartling\Jobs\Params\CreateJobParameters;
use Smartling\Jobs\Params\ListJobsParameters;
use Smartling\Jobs\Params\UpdateJobParameters;
use Smartling\MonologWrapper\MonologWrapper;
use Smartling\Project\ProjectApi;
use Smartling\Settings\ConfigurationProfileEntity;
use Smartling\Settings\SettingsManager;
use Smartling\Submissions\SubmissionEntity;

/**
 * Class ApiWrapper
 * @package Smartling
 */
class ApiWrapper implements ApiWrapperInterface
{

    /**
     * @var SettingsManager
     */
    private $settings;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var string
     */
    private $pluginName = '';

    /**
     * @var string
     */
    private $pluginVersion = '';

    /**
     * @return string
     */
    public function getPluginName()
    {
        return $this->pluginName;
    }

    /**
     * @param string $pluginName
     */
    public function setPluginName($pluginName)
    {
        $this->pluginName = $pluginName;
    }

    /**
     * @return string
     */
    public function getPluginVersion()
    {
        return $this->pluginVersion;
    }

    /**
     * @param string $pluginVersion
     */
    public function setPluginVersion($pluginVersion)
    {
        $this->pluginVersion = $pluginVersion;
    }

    /**
     * @return SettingsManager
     */
    public function getSettings()
    {
        return $this->settings;
    }

    /**
     * @param SettingsManager $settings
     */
    public function setSettings($settings)
    {
        $this->settings = $settings;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @return RuntimeCacheHelper
     */
    public function getCache()
    {
        return RuntimeCacheHelper::getInstance();
    }

    /**
     * ApiWrapper constructor.
     *
     * @param SettingsManager $manager
     * @param string          $pluginName
     * @param string          $pluginVersion
     */
    public function __construct(SettingsManager $manager, $pluginName, $pluginVersion)
    {
        $this->logger = MonologWrapper::getLogger(get_called_class());
        $this->setSettings($manager);
        $this->setPluginName($pluginName);
        $this->setPluginVersion($pluginVersion);
    }

    /**
     * @param SubmissionEntity $submission
     *
     * @return ConfigurationProfileEntity
     * @throws SmartlingDbException
     */
    private function getConfigurationProfile(SubmissionEntity $submission)
    {
        $profile = $this->getSettings()->getSingleSettingsProfile($submission->getSourceBlogId());
        LogContextMixinHelper::addToContext('projectId', $profile->getProjectId());

        return $profile;
    }

    /**
     * @param ConfigurationProfileEntity $profile
     *
     * @return AuthTokenProvider
     */
    private function getAuthProvider(ConfigurationProfileEntity $profile)
    {
        $cacheKey = 'profile.auth-provider.' . $profile->getId();
        $authProvider = $this->getCache()->get($cacheKey);

        if (false === $authProvider) {
            AuthTokenProvider::setCurrentClientId('wordpress-connector');
            AuthTokenProvider::setCurrentClientVersion($this->getPluginVersion());
            $authProvider = AuthTokenProvider::create(
                $profile->getUserIdentifier(),
                $profile->getSecretKey(),
                $this->getLogger()
            );
            $this->getCache()->set($cacheKey, $authProvider);
        }

        return $authProvider;
    }

    /**
     * @param SubmissionEntity $entity
     *
     * @return string
     * @throws SmartligFileDownloadException
     */
    public function downloadFile(SubmissionEntity $entity)
    {
        try {
            $profile = $this->getConfigurationProfile($entity);

            $api = FileApi::create(
                $this->getAuthProvider($profile),
                $profile->getProjectId(),
                $this->getLogger()
            );

            $this->getLogger()
                ->info(vsprintf(
                           'Starting file \'%s\' download for entity = \'%s\', blog = \'%s\', id = \'%s\', locale = \'%s\'.',
                           [
                               $entity->getFileUri(),
                               $entity->getContentType(),
                               $entity->getSourceBlogId(),
                               $entity->getSourceId(),
                               $entity->getTargetLocale(),
                           ]
                       ));

            $params = new DownloadFileParameters();

            $params->setRetrievalType($profile->getRetrievalType());

            $result = $api->downloadFile(
                $entity->getFileUri(),
                $this->getSmartlingLocaleBySubmission($entity),
                $params
            );

            return $result;

        } catch (\Exception $e) {
            $this->getLogger()
                ->error($e->getMessage());
            throw new SmartlingFileDownloadException($e->getMessage(), $e->getCode(), $e);

        }
    }

    /**
     * @param SubmissionEntity $entity
     * @param int              $completedStrings
     * @param int              $authorizedStrings
     * @param int              $totalStringCount
     * @param int              $excludedStringCount
     *
     * @return SubmissionEntity
     */
    private function setTranslationStatusToEntity(SubmissionEntity $entity, $completedStrings, $authorizedStrings, $totalStringCount, $excludedStringCount)
    {
        $entity->setApprovedStringCount($completedStrings + $authorizedStrings);
        $entity->setCompletedStringCount($completedStrings);
        $entity->setTotalStringCount($totalStringCount);
        $entity->setExcludedStringCount($excludedStringCount);

        return $entity;
    }

    /**
     * @param SubmissionEntity $entity
     *
     * @return SubmissionEntity
     * @throws SmartlingNetworkException
     */
    public function getStatus(SubmissionEntity $entity)
    {
        try {
            $locale = $this->getSmartlingLocaleBySubmission($entity);

            $profile = $this->getConfigurationProfile($entity);

            $api = FileApi::create(
                $this->getAuthProvider($profile),
                $profile->getProjectId(),
                $this->getLogger()
            );

            $data = $api->getStatus($entity->getFileUri(), $locale);

            $entity = $this
                ->setTranslationStatusToEntity($entity,
                                               $data['completedStringCount'],
                                               $data['authorizedStringCount'],
                                               $data['totalStringCount'],
                                               $data['excludedStringCount']
                );

            $entity->setWordCount($data['totalWordCount']);

            return $entity;

        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            throw new SmartlingNetworkException($e->getMessage());

        }
    }

    /**
     * @param ConfigurationProfileEntity $profile
     *
     * @return bool
     * @throws SmartlingNetworkException
     */
    public function testConnection(ConfigurationProfileEntity $profile)
    {
        try {
            $api = FileApi::create(
                $this->getAuthProvider($profile),
                $profile->getProjectId(),
                $this->getLogger()
            );

            $api->getList();

            return true;
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            throw new SmartlingNetworkException($e->getMessage());
        }
    }

    private function getSmartlingLocaleBySubmission(SubmissionEntity $entity)
    {
        $profile = $this->getSettings()->getSingleSettingsProfile($entity->getSourceBlogId());

        return $this->getSettings()->getSmartlingLocaleIdBySettingsProfile($profile, $entity->getTargetBlogId());
    }

    /**
     * {@inheritdoc}
     */
    public function uploadContent(SubmissionEntity $entity, $xmlString = '', $filename = '', array $smartlingLocaleList = [])
    {
        $this->getLogger()
            ->info(vsprintf(
                       'Starting file \'%s\' upload for entity = \'%s\', blog = \'%s\', id = \'%s\', locales:%s.',
                       [
                           $entity->getFileUri(),
                           $entity->getContentType(),
                           $entity->getSourceBlogId(),
                           $entity->getSourceId(),
                           implode(',', $smartlingLocaleList),
                       ]
                   ));
        try {
            $profile = $this->getConfigurationProfile($entity);

            $api = $this->getBatchApi($profile);

            $params = new UploadFileParameters('wordpress-connector', $this->getPluginVersion());
            $params->setLocalesToApprove($smartlingLocaleList);

            if (FileHelper::testFile($filename)) {
                $api->uploadBatchFile(
                    $filename,
                    $entity->getFileUri(),
                    'xml',
                    $entity->getBatchUid(),
                    $params);

                $message = vsprintf(
                    'Smartling uploaded \'%s\' for locales:%s.',
                    [
                        $entity->getFileUri(),
                        implode(',', $smartlingLocaleList),
                    ]
                );

                $this->logger->info($message);
            } else {
                $msg = vsprintf('File \'%s\' should exist if required for upload.', [$filename]);
                throw new \InvalidArgumentException($msg);
            }

            return true;
        } catch (\Exception $e) {
            throw new SmartlingFileUploadException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @inheritdoc
     */
    public function getSupportedLocales(ConfigurationProfileEntity $profile)
    {
        $supportedLocales = [];
        try {
            $api = ProjectApi::create(
                $this->getAuthProvider($profile),
                $profile->getProjectId(),
                $this->getLogger()
            );

            $locales = $api->getProjectDetails();

            foreach ($locales['targetLocales'] as $locale) {
                $supportedLocales[$locale['localeId']] = $locale['description'];
            }
        } catch (\Exception $e) {
            $message = vsprintf('Response has error messages. Message:\'%s\'.', [$e->getMessage()]);
            $this->logger->error($message);
        }

        return $supportedLocales;
    }

    /**
     * @param SubmissionEntity $submission
     *
     * @return array mixed
     * @throws SmartlingNetworkException
     */
    public function lastModified(SubmissionEntity $submission)
    {
        try {
            $profile = $this->getConfigurationProfile($submission);

            $api = FileApi::create(
                $this->getAuthProvider($profile),
                $profile->getProjectId(),
                $this->getLogger()
            );

            $data = $api->lastModified($submission->getFileUri());


            $output = [];

            foreach ($data['items'] as $descriptor) {
                $output[$descriptor['localeId']] = $descriptor['lastModified'];
            }

            return $output;

        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            throw new SmartlingNetworkException($e->getMessage());
        }
    }

    /**
     * @param SubmissionEntity[] $submissions
     *
     * @return Submissions\SubmissionEntity[]
     * @throws SmartlingNetworkException
     */
    public function getStatusForAllLocales(array $submissions)
    {
        try {
            /**
             * @var SubmissionEntity $submission
             */
            $submission = ArrayHelper::first($submissions);
            $profile = $this->getConfigurationProfile($submission);

            $api = FileApi::create(
                $this->getAuthProvider($profile),
                $profile->getProjectId(),
                $this->getLogger()
            );

            $data = $api->getStatusForAllLocales($submission->getFileUri());

            $totalWordCount = $data['totalWordCount'];

            unset($submission);

            foreach ($data['items'] as $descriptor) {
                $localeId = $descriptor['localeId'];

                if (array_key_exists($localeId, $submissions)) {
                    $completedStrings = $descriptor['completedStringCount'];
                    $authorizedStrings = $descriptor['authorizedStringCount'];
                    $totalStringCount = $data['totalStringCount'];
                    $excludedStringCount = $descriptor['excludedStringCount'];

                    $currentProgress = $submissions[$localeId]->getCompletionPercentage(); // current progress value 0..100

                    /**
                     * @var SubmissionEntity $submission
                     */
                    $submission = $this->setTranslationStatusToEntity(
                        $submissions[$localeId],
                        $completedStrings,
                        $authorizedStrings,
                        $totalStringCount,
                        $excludedStringCount);
                    $submission->setWordCount($totalWordCount);

                    $newProgress = $submissions[$localeId]->getCompletionPercentage(); // current progress value 0..100

                    if (100 === $newProgress && 100 === $currentProgress) {
                        /* nothing to do, removing from array to skip useless download */
                        $submissions[$localeId]->setStatus(SubmissionEntity::SUBMISSION_STATUS_COMPLETED);
                    }

                    if (100 > $newProgress && 100 === $currentProgress) {
                        /* just move from Completed to In Progress */
                        $submissions[$localeId]->setStatus(SubmissionEntity::SUBMISSION_STATUS_IN_PROGRESS);
                    }

                    if (100 === $newProgress && 100 > $currentProgress) {
                        /* just move from Completed to In Progress */
                        $submissions[$localeId]->setStatus(SubmissionEntity::SUBMISSION_STATUS_COMPLETED);
                    }

                    if (100 > $newProgress && 100 > $currentProgress) {
                        /* just move from Completed to In Progress */
                        $submissions[$localeId]->setStatus(SubmissionEntity::SUBMISSION_STATUS_IN_PROGRESS);
                    }
                }
            }

            return $submissions;

        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            throw new SmartlingNetworkException($e->getMessage());
        }
    }

    /**
     * @param SubmissionEntity $submission
     */
    public function deleteFile(SubmissionEntity $submission)
    {
        try {
            $profile = $this->getConfigurationProfile($submission);
            $api = FileApi::create(
                $this->getAuthProvider($profile),
                $profile->getProjectId(),
                $this->getLogger()
            );
            $api->deleteFile($submission->getFileUri());
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * @param ConfigurationProfileEntity $profile
     *
     * @return JobsApi
     */
    private function getJobsApi(ConfigurationProfileEntity $profile)
    {
        return JobsApi::create($this->getAuthProvider($profile), $profile->getProjectId(), $this->getLogger());
    }

    /**
     * @param \Smartling\Settings\ConfigurationProfileEntity $profile
     *
     * @return \Smartling\Jobs\JobsApi
     */
    private function getBatchApi(ConfigurationProfileEntity $profile) {
        return BatchApi::create($this->getAuthProvider($profile), $profile->getProjectId(), $this->getLogger());
    }

    /**
     * @param ConfigurationProfileEntity $profile
     *
     * @param null $name
     * @param array $statuses
     * @return array
     */
    public function listJobs(ConfigurationProfileEntity $profile, $name = null, array $statuses = [])
    {
        $params = new ListJobsParameters();

        if (!empty($name)) {
            $params->setName($name);
        }

        if (!empty($statuses)) {
            $params->setStatuses($statuses);
        }

        return $this->getJobsApi($profile)->listJobs($params);
    }

    /**
     * {@inheritdoc}
     */
    public function createJob(ConfigurationProfileEntity $profile, array $params)
    {
        $param = new CreateJobParameters();

        $param->setDescription($params['description']);
        $param->setDueDate($params['dueDate']);
        $param->setTargetLocales($params['locales']);
        $param->setName($params['name']);

        return $this->getJobsApi($profile)->createJob($param);
    }

    /**
     * {@inheritdoc}
     */
    public function updateJob(ConfigurationProfileEntity $profile, $jobId, $name, $description, $dueDate)
    {
        $params = new UpdateJobParameters();
        $params->setName($name);

        if (!empty($description)) {
            $params->setDescription($description);
        }

        if (!empty($dueDate)) {
            $time_zone = new DateTimeZone('UTC');
            $date = new DateTime($dueDate, $time_zone);
            $params->setDueDate($date);
        }

        return $this->getJobsApi($profile)->updateJob($jobId, $params);
    }

    /**
     * @param \Smartling\Settings\ConfigurationProfileEntity $profile
     * @param $jobId
     * @param bool $authorize
     *
     * @return array
     * @throws SmartlingApiException
     */
    public function createBatch(ConfigurationProfileEntity $profile, $jobId, $authorize = false)
    {
        $createBatchParameters = new CreateBatchParameters();
        $createBatchParameters->setTranslationJobUid($jobId);
        $createBatchParameters->setAuthorize($authorize);

        return $this->getBatchApi($profile)->createBatch($createBatchParameters);
    }

    /**
     * {@inheritdoc}
     */
    public function executeBatch(ConfigurationProfileEntity $profile, $batchUid)
    {
        $this->getBatchApi($profile)->executeBatch($batchUid);
    }
}
<?php

namespace Smartling\Tests;

use PHPUnit\Framework\TestCase;
use Smartling\Helpers\DetectChangesHelper;
use Smartling\Helpers\WordpressContentTypeHelper;
use Smartling\Settings\SettingsManager;
use Smartling\Submissions\SubmissionEntity;
use Smartling\Tests\Mocks\WordpressFunctionsMockHelper;
use Smartling\Tests\Traits\DummyLoggerMock;
use Smartling\Tests\Traits\InvokeMethodTrait;
use Smartling\Tests\Traits\SettingsManagerMock;
use Smartling\Tests\Traits\SubmissionEntityMock;
use Smartling\Settings\ConfigurationProfileEntity;

class DetectChangesTest extends TestCase
{
    use DummyLoggerMock;
    use SubmissionEntityMock;
    use InvokeMethodTrait;
    use SettingsManagerMock;

    private $detectChangesHelperMock;

    protected function setUp(): void
    {
        $mock = $this->createMock(DetectChangesHelper::class);

        $profileMock = $this->createMock(ConfigurationProfileEntity::class);

        $settingsManager = $this->createPartialMock(SettingsManager::class, ['getSingleSettingsProfile']);
        $settingsManager->method('getSingleSettingsProfile')->willReturn($profileMock);

        $mock->method('getSettingsManager')->willReturn($settingsManager);

        $this->detectChangesHelperMock = $mock;
    }

    /**
     * @dataProvider checkSubmissionHashDataProvider
     *
     * @param array  $submissionFields
     * @param bool   $needStatusChange
     * @param string $newHash
     */
    public function testCheckSubmissionHash(array $submissionFields, bool $needStatusChange, string $newHash)
    {
        WordpressFunctionsMockHelper::injectFunctionsMocks();
        $initialSubmission = SubmissionEntity::fromArray($submissionFields, $this->getLogger());
        $submission = SubmissionEntity::fromArray($submissionFields, $this->getLogger());

        $processedSubmission = $this->invokeMethod(
            $this->detectChangesHelperMock,
            'checkSubmissionHash',
            [
                $submission,
                $needStatusChange,
                $newHash,
            ]
        );

        if ($initialSubmission->getSourceContentHash() === $newHash) {
            self::assertEquals(SubmissionEntity::FLAG_CONTENT_IS_UP_TO_DATE, $processedSubmission->getOutdated());
        } else {
            self::assertEquals(SubmissionEntity::FLAG_CONTENT_IS_OUT_OF_DATE, $processedSubmission->getOutdated());
        }

        if (true === $needStatusChange) {
            self::assertEquals(SubmissionEntity::SUBMISSION_STATUS_NEW, $processedSubmission->getStatus());
        } else {
            self::assertEquals($initialSubmission->getStatus(), $processedSubmission->getStatus());
        }
    }

    public function checkSubmissionHashDataProvider(): array
    {
        return [
            [

                [
                    'id'                     => 1,
                    'source_title'           => '',
                    'source_blog_id'         => 1,
                    'source_content_hash'    => 'abc',
                    'content_type'           => 'post',
                    'source_id'              => 1,
                    'file_uri'               => 'any',
                    'target_locale'          => 'any',
                    'target_blog_id'         => 0,
                    'target_id'              => 0,
                    'submitter'              => 'any',
                    'submission_date'        => 'any',
                    'applied_date'           => 'any',
                    'approved_string_count'  => 0,
                    'completed_string_count' => 0,
                    'status'                 => SubmissionEntity::SUBMISSION_STATUS_IN_PROGRESS,
                    'is_locked'              => 0,
                    'outdated'               => SubmissionEntity::FLAG_CONTENT_IS_UP_TO_DATE,
                ],
                false,
                'abc',
            ],
            [

                [
                    'id'                     => 1,
                    'source_title'           => '',
                    'source_blog_id'         => 1,
                    'source_content_hash'    => 'abc',
                    'content_type'           => 'post',
                    'source_id'              => 1,
                    'file_uri'               => 'any',
                    'target_locale'          => 'any',
                    'target_blog_id'         => 0,
                    'target_id'              => 0,
                    'submitter'              => 'any',
                    'submission_date'        => 'any',
                    'applied_date'           => 'any',
                    'approved_string_count'  => 0,
                    'completed_string_count' => 0,
                    'status'                 => SubmissionEntity::SUBMISSION_STATUS_IN_PROGRESS,
                    'is_locked'              => 0,
                    'outdated'               => SubmissionEntity::FLAG_CONTENT_IS_OUT_OF_DATE,
                ],
                false,
                'abc',
            ],
            [

                [
                    'id'                     => 1,
                    'source_title'           => '',
                    'source_blog_id'         => 1,
                    'source_content_hash'    => 'abc',
                    'content_type'           => 'post',
                    'source_id'              => 1,
                    'file_uri'               => 'any',
                    'target_locale'          => 'any',
                    'target_blog_id'         => 0,
                    'target_id'              => 0,
                    'submitter'              => 'any',
                    'submission_date'        => 'any',
                    'applied_date'           => 'any',
                    'approved_string_count'  => 0,
                    'completed_string_count' => 0,
                    'status'                 => SubmissionEntity::SUBMISSION_STATUS_IN_PROGRESS,
                    'is_locked'              => 0,
                    'outdated'               => SubmissionEntity::FLAG_CONTENT_IS_OUT_OF_DATE,
                ],
                false,
                'def',
            ],
            [

                [
                    'id'                     => 1,
                    'source_title'           => '',
                    'source_blog_id'         => 1,
                    'source_content_hash'    => 'abc',
                    'content_type'           => 'post',
                    'source_id'              => 1,
                    'file_uri'               => 'any',
                    'target_locale'          => 'any',
                    'target_blog_id'         => 0,
                    'target_id'              => 0,
                    'submitter'              => 'any',
                    'submission_date'        => 'any',
                    'applied_date'           => 'any',
                    'approved_string_count'  => 0,
                    'completed_string_count' => 0,
                    'status'                 => SubmissionEntity::SUBMISSION_STATUS_NEW,
                    'is_locked'              => 0,
                    'outdated'               => SubmissionEntity::FLAG_CONTENT_IS_OUT_OF_DATE,
                ],
                true,
                'def',
            ],
        ];
    }
}

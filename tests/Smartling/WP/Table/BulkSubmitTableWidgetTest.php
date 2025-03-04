<?php

namespace {
    if (!class_exists('WP_List_Table')) {
        class WP_List_Table
        {
            public function __construct($a)
            {
            }

            public function get_pagenum()
            {
                return 1;
            }

            public function set_pagination_args($args)
            {
            }
        }
    }

    if (!function_exists('__')) {
        function __($a)
        {
            return $a;
        }
    }

    if (!function_exists('convert_to_screen')) {
        function convert_to_screen($a)
        {
            $r = new \stdClass();
            $r->id = $a;
            return $r;
        }
    }
}

namespace Smartling\Tests\Smartling\WP\Table {

    use PHPUnit\Framework\TestCase;
    use Smartling\Base\SmartlingCore;
    use Smartling\DbAl\WordpressContentEntities\EntityAbstract;
    use Smartling\Helpers\EntityHelper;
    use Smartling\Helpers\PluginInfo;
    use Smartling\Jobs\JobEntityWithBatchUid;
    use Smartling\Processors\ContentEntitiesIOFactory;
    use Smartling\Settings\ConfigurationProfileEntity;
    use Smartling\Settings\Locale;
    use Smartling\Submissions\SubmissionEntity;
    use Smartling\Submissions\SubmissionManager;
    use Smartling\Tests\Mocks\WordpressFunctionsMockHelper;
    use Smartling\WP\Table\BulkSubmitTableWidget;

    class BulkSubmitTableWidgetTest extends TestCase
    {
        public function testClone()
        {
            WordpressFunctionsMockHelper::injectFunctionsMocks();
            $currentBlogId = 1;
            $projectUid = 'projectUid';
            $submissionId = 3;
            $submissionType = 'post';
            $targetBlogId = 5;

            $entityHelper = $this->createMock(EntityHelper::class);
            $manager = $this->createMock(SubmissionManager::class);
            $manager->method('getPageSize')->willReturn(7);
            $pluginInfo = $this->createMock(PluginInfo::class);

            $contentEntitiesIOFactory = $this->createMock(ContentEntitiesIOFactory::class);
            $contentEntitiesIOFactory->method('getMapper')->willReturn($this->getMockForAbstractClass(EntityAbstract::class));

            $locale = $this->createMock(Locale::class);
            $locale->method('getBlogId')->willReturn($currentBlogId);

            $core = $this->createMock(SmartlingCore::class);
            $core->method('getContentIoFactory')->willReturn($contentEntitiesIOFactory);
            $core->expects($this->once())->method('createForTranslation')->with($submissionType, $currentBlogId, $submissionId, $targetBlogId)->willReturnCallback(function (string $contentType, int $sourceBlog, int $sourceEntity, int $targetBlog, JobEntityWithBatchUid $jobInfo, bool $clone) use ($projectUid) {
                $this->assertEquals('', $jobInfo->getBatchUid());
                $jobInfo = $jobInfo->getJobInformationEntity();
                $this->assertEquals(null, $jobInfo->getId());
                $this->assertEquals('', $jobInfo->getJobName());
                $this->assertEquals('', $jobInfo->getJobUid());
                $this->assertEquals($projectUid, $jobInfo->getProjectUid());
                $this->assertTrue($clone);
                return new SubmissionEntity();
            });

            $profile = $this->createMock(ConfigurationProfileEntity::class);
            $profile->method('getOriginalBlogId')->willReturn($locale);
            $profile->method('getProjectId')->willReturn($projectUid);

            $x = new BulkSubmitTableWidget($core, $manager, $pluginInfo, $entityHelper, $profile);
            $x->setSource([
                'smartling-bulk-submit-page-content-type' => $submissionType,
                'smartling-bulk-submit-page-submission' => ["$submissionId-$submissionType"],
                'jobName' => '',
                'description-sm' => '',
                'dueDate' => '',
                'bulk-submit-locales' => ['locales' => [$targetBlogId => [
                    'blog' => (string)$targetBlogId,
                    'locale' => 'Test',
                    'enabled' => 'on',
                ]]],
                'action' => 'clone',
            ]);
            $x->prepare_items();
        }
    }
}

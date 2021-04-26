<?php

namespace Smartling\Tests\Smartling\Base;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Smartling\Base\SmartlingCoreUploadTrait;
use Smartling\DbAl\WordpressContentEntities\PostEntityStd;
use Smartling\Helpers\ContentHelper;
use Smartling\Helpers\DecodedXml;
use Smartling\Helpers\FieldsFilterHelper;
use Smartling\Helpers\GutenbergBlockHelper;
use Smartling\Helpers\PostContentHelper;
use Smartling\Helpers\XmlHelper;
use Smartling\Settings\ConfigurationProfileEntity;
use Smartling\Settings\SettingsManager;
use Smartling\Submissions\SubmissionEntity;
use Smartling\Submissions\SubmissionManager;
use Smartling\Tests\Mocks\WordpressFunctionsMockHelper;

require __DIR__ . '/../../wordpressBlocks.php';

class SmartlingCoreUpload {
    use SmartlingCoreUploadTrait;

    private ContentHelper $contentHelper;
    private FieldsFilterHelper $fieldsFilterHelper;
    private SettingsManager $settingsManager;
    private SubmissionManager $submissionManager;

    public function __construct(ContentHelper $contentHelper, FieldsFilterHelper $fieldsFilterHelper, SettingsManager $settingsManager, SubmissionManager $submissionManager)
    {
        $this->contentHelper = $contentHelper;
        $this->fieldsFilterHelper = $fieldsFilterHelper;
        $this->settingsManager = $settingsManager;
        $this->submissionManager = $submissionManager;
    }

    public function getLogger(): NullLogger
    {
        return new NullLogger();
    }

    public function prepareFieldProcessorValues()
    {
    }

    public function prepareRelatedSubmissions()
    {
    }

    public function getContentHelper(): ContentHelper
    {
        return $this->contentHelper;
    }

    public function getFieldsFilter(): FieldsFilterHelper
    {
        return $this->fieldsFilterHelper;
    }

    public function getSettingsManager(): SettingsManager
    {
        return $this->settingsManager;
    }

    public function getSubmissionManager(): SubmissionManager
    {
        return $this->submissionManager;
    }
}

class SmartlingCoreUploadTraitTest extends TestCase
{
    private PostEntityStd $resultEntity;

    public function setUp(): void
    {
        WordpressFunctionsMockHelper::injectFunctionsMocks();
    }

    public function testApplyXmlNoCleanMetadata()
    {
        $submission = $this->createMock(SubmissionEntity::class);
        $translatedFields = ['metaNotToTranslate' => 's:8:"Translated"', 'metaToTranslate' => '~Translated~'];

        $contentHelper = $this->getMockBuilder(ContentHelper::class)->disableOriginalConstructor()->getMock();
        $contentHelper->method('readSourceContent')->willReturnArgument(0);
        $contentHelper->method('readSourceMetadata')->willReturn([]);
        $contentHelper->method('readTargetContent')->willReturn(new PostEntityStd());

        $fieldsFilterHelper = $this->getMockBuilder(FieldsFilterHelper::class)->disableOriginalConstructor()->getMock();
        $fieldsFilterHelper->method('processStringsAfterDecoding')->willReturnArgument(0);
        $fieldsFilterHelper->method('applyTranslatedValues')->willReturnArgument(2);

        $settingsManager = $this->getMockBuilder(SettingsManager::class)->disableOriginalConstructor()->getMock();
        $settingsManager->method('getSingleSettingsProfile')->willReturn($this->createMock(ConfigurationProfileEntity::class));

        $submissionManager = $this->getMockBuilder(SubmissionManager::class)->disableOriginalConstructor()->getMock();
        $submissionManager->method('storeEntity')->willReturnArgument(0);

        $x = new SmartlingCoreUpload($contentHelper, $fieldsFilterHelper, $settingsManager, $submissionManager);
        $xmlHelper = $this->createMock(XmlHelper::class);
        $xmlHelper->method('xmlDecode')->willReturn(new DecodedXml(
            ['meta' => $translatedFields],
            ['meta' => ['metaNotToTranslate' => 's:8:"Original"', 'metaToTranslate' => 'Original']]
        ));

        $contentHelper->expects(self::never())->method('removeTargetMetadata');
        $contentHelper->expects(self::once())->method('writeTargetMetadata')->with($submission, $translatedFields);
        $this->assertSuccessApplyXml($x, $submission, $xmlHelper);
    }

    public function testApplyXmlCleanMetadata()
    {
        $submission = $this->createMock(SubmissionEntity::class);
        $submission->method('getLockedFields')->willReturn(['meta/locked']);
        $submission->method('getTargetId')->willReturn(1 );
        $contentHelper = $this->getMockBuilder(ContentHelper::class)->disableOriginalConstructor()->getMock();
        $contentHelper->method('readSourceContent')->willReturnArgument(0);
        $contentHelper->method('readSourceMetadata')->willReturn([]);
        $contentHelper->method('readTargetContent')->willReturn(new PostEntityStd());
        $contentHelper->method('readTargetMetadata')->willReturn(['locked' => 'locked', 'unlocked' => 'unlocked']);

        $fieldsFilterHelper = $this->createPartialMock(FieldsFilterHelper::class, ['applyTranslatedValues', 'getLogger', 'processStringsAfterDecoding']);
        $fieldsFilterHelper->method('processStringsAfterDecoding')->willReturnArgument(0);
        $fieldsFilterHelper->method('applyTranslatedValues')->willReturnArgument(2);
        $fieldsFilterHelper->method('getLogger')->willReturn(new NullLogger());

        $profile = $this->getMockBuilder(ConfigurationProfileEntity::class)->disableOriginalConstructor()->getMock();
        $profile->method('getCleanMetadataOnDownload')->willReturn(1);
        $profile->method('getFilterFieldNameRegExp')->willReturn(true);
        $profile->method('getFilterSkipArray')->willReturn(['excluded']);

        $settingsManager = $this->getMockBuilder(SettingsManager::class)->disableOriginalConstructor()->getMock();
        $settingsManager->method('getSingleSettingsProfile')->willReturn($profile);

        $submissionManager = $this->getMockBuilder(SubmissionManager::class)->disableOriginalConstructor()->getMock();
        $submissionManager->method('storeEntity')->willReturnArgument(0);

        $x = new SmartlingCoreUpload($contentHelper, $fieldsFilterHelper, $settingsManager, $submissionManager);
        $xmlHelper = $this->createMock(XmlHelper::class);
        $xmlHelper->method('xmlDecode')->willReturn(new DecodedXml(
            ['meta' => ['metaToTranslate' => '~Translated~']],
            ['meta' => ['excludedField' => 'excluded', 'sourceMetaField' => 'set', 'metaToTranslate' => 'Original']]
        ));

        $contentHelper->expects(self::once())->method('removeTargetMetadata');
        $contentHelper->expects(self::once())->method('writeTargetMetadata')->with($submission, ['sourceMetaField' => 'set', 'metaToTranslate' => '~Translated~', 'locked' => 'locked']);
        $this->assertSuccessApplyXml($x, $submission, $xmlHelper);
    }

    public function testApplyXmlLockedBlocks()
    {
        $translatedContent = <<<HTML
<!-- wp:paragraph {"placeholder":"Translated first paragraph","fontSize":"large"} -->
<p class="has-large-font-size">Translated first paragraph</p>
<!-- /wp:paragraph -->
<p>Translated not Gutenberg block</p>
<!-- wp:paragraph {"placeholder":"Translated second paragraph","fontSize":"large"} -->
<p class="has-large-font-size">Translated second paragraph</p>
<!-- /wp:paragraph -->
<p>Translated other non-Gutenberg content</p>
<!-- wp:paragraph {"placeholder":"Translated third paragraph","fontSize":"large"} -->
<p class="has-large-font-size">Translated third paragraph</p>
<!-- /wp:paragraph -->
HTML;
        $originalContent = <<<HTML
<!-- wp:paragraph {"placeholder":"First paragraph","fontSize":"large"} -->
<p class="has-large-font-size">First paragraph</p>
<!-- /wp:paragraph -->
<p>Not a Gutenberg block</p>
<!-- wp:paragraph {"placeholder":"Second paragraph","fontSize":"large"} -->
<p class="has-large-font-size">Second paragraph</p>
<!-- /wp:paragraph -->
<p>Other non-Gutenberg content</p>
<!-- wp:paragraph {"placeholder":"Third paragraph","fontSize":"large"} -->
<p class="has-large-font-size">Third paragraph</p>
<!-- /wp:paragraph -->
HTML;
        $targetContent = <<<HTML
<!-- wp:paragraph {"placeholder":"Translated first paragraph","fontSize":"large"} -->
<p class="has-large-font-size">Translated first paragraph with changes (not locked)</p>
<!-- /wp:paragraph -->
<p>Translated not Gutenberg block</p>
<!-- wp:paragraph {"placeholder":"Translated second paragraph","fontSize":"large"} -->
<p class="has-large-font-size">Translated second paragraph with changes (locked)</p>
<!-- /wp:paragraph -->
<p>Translated other non-Gutenberg content</p>
<!-- wp:paragraph {"placeholder":"Translated third paragraph","fontSize":"large"} -->
<p class="has-large-font-size">Translated third paragraph</p>
<!-- /wp:paragraph -->
HTML;
        $expectedContent = <<<HTML
<!-- wp:paragraph {"placeholder":"Translated first paragraph","fontSize":"large"} -->
<p class="has-large-font-size">Translated first paragraph</p>
<!-- /wp:paragraph -->
<p>Translated not Gutenberg block</p>
<!-- wp:paragraph {"placeholder":"Translated second paragraph","fontSize":"large"} -->
<p class="has-large-font-size">Translated second paragraph with changes (locked)</p>
<!-- /wp:paragraph -->
<p>Translated other non-Gutenberg content</p>
<!-- wp:paragraph {"placeholder":"Translated third paragraph","fontSize":"large"} -->
<p class="has-large-font-size">Translated third paragraph</p>
<!-- /wp:paragraph -->
HTML;

        $target = new PostEntityStd();
        $target->setPostContent($targetContent);

        $submission = $this->createMock(SubmissionEntity::class);
        $submission->method('getLockedFields')->willReturn(['entity/post_content/blocks/1']);
        $submission->method('getTargetId')->willReturn(1);
        $contentHelper = $this->getMockBuilder(ContentHelper::class)->disableOriginalConstructor()->getMock();
        $contentHelper->method('readSourceContent')->willReturnArgument(0);
        $contentHelper->method('readSourceMetadata')->willReturn([]);
        $contentHelper->method('readTargetContent')->willReturn($target);
        $contentHelper->method('readTargetMetadata')->willReturn([]);

        $fieldsFilterHelper = $this->createPartialMock(FieldsFilterHelper::class, ['applyTranslatedValues', 'getLogger', 'processStringsAfterDecoding']);
        $fieldsFilterHelper->method('processStringsAfterDecoding')->willReturnArgument(0);
        $fieldsFilterHelper->method('applyTranslatedValues')->willReturnArgument(2);
        $fieldsFilterHelper->method('getLogger')->willReturn(new NullLogger());

        $profile = $this->getMockBuilder(ConfigurationProfileEntity::class)->disableOriginalConstructor()->getMock();

        $settingsManager = $this->getMockBuilder(SettingsManager::class)->disableOriginalConstructor()->getMock();
        $settingsManager->method('getSingleSettingsProfile')->willReturn($profile);

        $submissionManager = $this->getMockBuilder(SubmissionManager::class)->disableOriginalConstructor()->getMock();
        $submissionManager->method('storeEntity')->willReturnArgument(0);

        $x = new SmartlingCoreUpload($contentHelper, $fieldsFilterHelper, $settingsManager, $submissionManager);
        $xmlHelper = $this->createMock(XmlHelper::class);
        $xmlHelper->method('xmlDecode')->willReturn(new DecodedXml(
            ['entity' => ['post_content' => $translatedContent]],
            ['entity' => ['post_content' => $originalContent]]
        ));

        $contentHelper->expects(self::once())->method('writeTargetContent')->willReturnCallback(function (SubmissionEntity $submission, PostEntityStd $entity) {
            $this->resultEntity = $entity;
    });

        $this->assertSuccessApplyXml($x, $submission, $xmlHelper);
        self::assertEquals($expectedContent, $this->resultEntity->post_content);
    }

    private function assertSuccessApplyXml(SmartlingCoreUpload $smartlingCoreUpload, SubmissionEntity $submission, XmlHelper $xmlHelper)
    {
        self::assertEquals([], $smartlingCoreUpload->applyXML($submission, ' ', $xmlHelper, new PostContentHelper(new GutenbergBlockHelper())));
    }
}

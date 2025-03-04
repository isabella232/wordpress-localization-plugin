<?php

namespace IntegrationTests\tests;

use Smartling\Helpers\ArrayHelper;
use Smartling\Helpers\SiteHelper;
use Smartling\Helpers\TranslationHelper;
use Smartling\Submissions\SubmissionManager;
use Smartling\Tests\IntegrationTests\SmartlingUnitTestCaseAbstract;
use Smartling\Tuner\MediaAttachmentRulesManager;

class GutenbergBlocksTest extends SmartlingUnitTestCaseAbstract
{
    private MediaAttachmentRulesManager $rulesManager;
    private SiteHelper $siteHelper;
    private SubmissionManager $submissionManager;
    private TranslationHelper $translationHelper;
    private int $sourceBlogId = 1;
    private int $targetBlogId = 2;

    public function __construct($name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        $this->rulesManager = $this->getRulesManager();
        $this->siteHelper = $this->getSiteHelper();
        $this->submissionManager = $this->getSubmissionManager();
        $this->translationHelper = $this->getTranslationHelper();
    }

    public function testInnerBlocks()
    {
        $content = <<<HTML
<!-- wp:sf/fourup-blade-layout-one {"backgroundMediaId":57,"backgroundMediaUrl":"base-url/en-us/wp-content/uploads/sites/4/2021/04/Bridge.jpg","accentMediaId":21,"accentMediaUrl":"base-url/en-us/wp-content/uploads/sites/4/2021/04/Highway.jpg","accentMobileMediaId":21,"accentMobileMediaUrl":"base-url/en-us/wp-content/uploads/sites/4/2021/04/Highway.jpg","backgroundMobileMediaId":57,"backgroundMobileMediaUrl":"base-url/en-us/wp-content/uploads/sites/4/2021/04/Bridge.jpg"} -->
<div class="wp-block-sf-fourup-blade-layout-one"><!-- wp:sf/post {"id":"bc321c81-f35a-475b-aeef-d0ea1183864a"} /-->

<!-- wp:sf/post {"id":1} /-->

<!-- wp:sf/post {"id":2} /-->

<!-- wp:sf/post {"id":3} /--></div>
<!-- /wp:sf/fourup-blade-layout-one -->
<p>Not a Gutenberg block</p>
HTML;
        $postIds = [];
        $submissions = [];
        for ($id = 1; $id < 4; $id++) {
            $postIds[] = $this->createPost('post', "title $id", "Post $id content");
        }
        $postIds[] = $this->createPost('post', 'main title', $content);

        // manual ordering to force ids change on target site
        $submissions[] = $this->translationHelper->prepareSubmission('post', $this->sourceBlogId, $postIds[1], $this->targetBlogId);
        $submissions[] = $this->translationHelper->prepareSubmission('post', $this->sourceBlogId, $postIds[2], $this->targetBlogId);
        $submissions[] = $this->translationHelper->prepareSubmission('post', $this->sourceBlogId, $postIds[0], $this->targetBlogId);
        $submissions[] = $this->translationHelper->prepareSubmission('post', $this->sourceBlogId, $postIds[3], $this->targetBlogId);

        foreach ($submissions as $submission) {
            $submission->getFileUri();
            $this->submissionManager->storeEntity($submission);
        }
        $this->withBlockRules($this->rulesManager, ['test' => [
            'block' => 'sf/post',
            'path' => 'id',
            'replacerId' => 'related|post',
        ]], function () use ($submissions) {
            $this->executeUpload();
            $this->forceSubmissionDownload($submissions[3]);
        });

        foreach ($submissions as &$submission) {
            $submission = $this->translationHelper->reloadSubmission($submission);
        }
        unset($submission);
        $submission = ArrayHelper::first($this->submissionManager->find(['id' => $submissions[3]->getId()]));

        $blocks = $this->getGutenbergBlockHelper()->parseBlocks($this->getTargetPost($this->siteHelper, $submission)->post_content);
        $this->assertCount(2, $blocks, 'Expected to have an wp:sf/fourup-blade-layout-one block, and non-Gutenberg block');
        $innerBlocks = $blocks[0]->getInnerBlocks();
        $this->assertCount(4, $innerBlocks);
        $this->assertEquals('[b~c321~c81-~f35á~-475~b-áé~éf-d~0éá1~1838~64á]', $innerBlocks[0]->getAttributes()['id'], 'Expected non-numeric id property to be translated');
        for ($id = 1; $id < 4; $id++) {
            $this->assertEquals(ArrayHelper::first(array_filter($submissions, static function ($submission) use ($id) {
                return $submission->getSourceId() === $id;
            }))->getTargetId(), $innerBlocks[$id]->getAttributes()['id'], 'Expected id to equal target id');
        }
        $this->assertEquals("\n<p>[Ñ~ót á ~Gúté~ñbé~rg bl~óck]</p>", $blocks[1]->getInnerHtml(), 'Expected non-Gutenberg block to be translated');
    }

    public function testCopyAndExclude()
    {
        $content = <<<HTML
<!-- wp:si/block {"otherAttribute":"otherValue","copyAttribute":"copyValue","excludeAttribute":"excludeValue"} -->
<!-- wp:si/nested {"copyAttribute":"ca2"} -->
<p>Nested 1 content</p>
<!-- /wp:si/nested -->
<!-- wp:si/nested {"excludeAttribute":"ca3"} -->
<p>Nested 2 content</p>
<!-- /wp:si/nested -->
<!-- /wp:si/block -->
HTML;
        $expected = <<<HTML
<!-- wp:si/block {"otherAttribute":"[ó~thé~rVá~lúé]","copyAttribute":"copyValue","excludeAttribute":null} -->
<!-- wp:si/nested {"copyAttribute":"[c~á2]"} -->
<p>[Ñ~ést~éd 1 c~óñt~éñt]</p>
<!-- /wp:si/nested -->
<!-- wp:si/nested {"excludeAttribute":"[c~á3]"} -->
<p>[Ñ~ést~éd 2 c~óñt~éñt]</p>
<!-- /wp:si/nested -->
<!-- /wp:si/block -->
HTML;
        $postId = $this->createPost('post', 'main title', $content);
        $submission = $this->translationHelper->prepareSubmission('post', $this->sourceBlogId, $postId, $this->targetBlogId);
        $submission->getFileUri();
        $this->submissionManager->storeEntity($submission);
        $this->withBlockRules($this->rulesManager, [
            'copy' => [
                'block' => 'si/block',
                'path' => 'copy',
                'replacerId' => 'copy',
            ],
            'exclude' => [
                'block' => 'si/block',
                'path' => 'exclude',
                'replacerId' => 'exclude',
            ],
        ], function () use ($submission) {
            $this->executeUpload();
            $this->forceSubmissionDownload($submission);
        });

        $submission = $this->translationHelper->reloadSubmission($submission);

        $this->assertEquals($expected, $this->getTargetPost($this->siteHelper, $submission)->post_content);
    }

    public function testCoreImageClassTranslation()
    {
        $attachmentSourceId = $this->createAttachment();
        $content = <<<HTML
<!-- wp:image {"id":$attachmentSourceId,"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="http://example.com/wp-content/uploads/2021/11/imageClass.png" alt="" class="wp-image-$attachmentSourceId"/></figure>
<!-- /wp:image -->
<!-- wp:image {"id":$attachmentSourceId,"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img class="wp-image-$attachmentSourceId" src="http://example.com/wp-content/uploads/2021/11/imageClass.png" alt="" /></figure>
<!-- /wp:image -->
<!-- wp:image {"id":$attachmentSourceId,"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="http://example.com/wp-content/uploads/2021/11/imageClass.png" alt="" class="irrelevant wp-image-$attachmentSourceId someOtherClass"/></figure>
<!-- /wp:image -->
HTML;
        $postId = $this->createPost('post', "Image Class Translation", $content);
        $attachment = $this->translationHelper->prepareSubmission('attachment', $this->sourceBlogId, $attachmentSourceId, $this->targetBlogId);
        $post = $this->translationHelper->prepareSubmission('post', $this->sourceBlogId, $postId, $this->targetBlogId);
        $submissions = [$attachment, $post];
        foreach ($submissions as $submission) {
            $submission->getFileUri();
            $this->submissionManager->storeEntity($submission);
        }
        $this->executeUpload();
        $this->forceSubmissionDownload($submissions[0]);
        $this->forceSubmissionDownload($submissions[1]);
        foreach ($submissions as &$submission) {
            $submission = $this->translationHelper->reloadSubmission($submission);
        }
        unset($submission);
        $attachmentTargetId = $submissions[0]->getTargetId();
        $expectedContent = <<<HTML
<!-- wp:image {"id":$attachmentTargetId,"sizeSlug":"[l~árgé]"} -->
<figure class="wp-block-image size-large"><img src="http://example.com/wp-content/uploads/2021/11/imageClass.png" alt="" class="wp-image-$attachmentTargetId" /></figure>
<!-- /wp:image -->
<!-- wp:image {"id":$attachmentTargetId,"sizeSlug":"[l~árgé]"} -->
<figure class="wp-block-image size-large"><img class="wp-image-$attachmentTargetId" src="http://example.com/wp-content/uploads/2021/11/imageClass.png" alt="" /></figure>
<!-- /wp:image -->
<!-- wp:image {"id":$attachmentTargetId,"sizeSlug":"[l~árgé]"} -->
<figure class="wp-block-image size-large"><img src="http://example.com/wp-content/uploads/2021/11/imageClass.png" alt="" class="irrelevant wp-image-$attachmentTargetId someOtherClass" /></figure>
<!-- /wp:image -->
HTML;
        $this->assertNotEquals($attachmentSourceId, $attachmentTargetId);
        $this->assertEquals($expectedContent, $this->getTargetPost($this->siteHelper, $submissions[1])->post_content);
    }
}

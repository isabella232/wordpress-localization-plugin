<?php

namespace {
    if (!class_exists('WP_Term')) {
        class WP_Term
        {
            public $name;
            public $taxonomy;
            public $term_id;

            public function __construct($object)
            {
                $this->name = $object->name;
                $this->taxonomy = $object->taxonomy;
                $this->term_id = $object->term_id;
            }
        }
    }

    if (!function_exists('get_current_blog_id')) {
        function get_current_blog_id()
        {
            return 1;
        }
    }
}

namespace Smartling\Tests\Smartling\WP\Controller {

    use PHPUnit\Framework\TestCase;
    use Smartling\DbAl\LocalizationPluginProxyInterface;
    use Smartling\Helpers\PluginInfo;
    use Smartling\Helpers\SiteHelper;
    use Smartling\Helpers\WordpressFunctionProxyHelper;
    use Smartling\Submissions\SubmissionEntity;
    use Smartling\Submissions\SubmissionManager;
    use Smartling\WP\Controller\TaxonomyLinksController;

    class TaxonomyLinksControllerTest extends TestCase
    {
        /**
         * @dataProvider getSourceTermsDataProvider
         * @param array $expected
         * @param array $terms
         * @param array $submissions
         */
        public function testGetSourceTerms(array $expected, array $terms, array $submissions)
        {
            $wordpress = $this->getMock(WordpressFunctionProxyHelper::class);
            $wordpress->method('get_terms')->willReturnOnConsecutiveCalls($terms['source'], $terms['target']);

            $siteHelper = $this->getMock(SiteHelper::class);
            $siteHelper->method('listBlogs')->willReturn([]);

            $x = new TaxonomyLinksController(
                $this->getMockBuilder(PluginInfo::class)->disableOriginalConstructor()->getMock(),
                $this->getMock(LocalizationPluginProxyInterface::class),
                $siteHelper,
                $this->getSubmissionManager($submissions),
                $wordpress
            );

             $x->getTerms();
        }

        public function getSourceTermsDataProvider()
        {
            $defaultTerms = [
                'source' => [new \WP_Term($this->getTermObject(1, 'category 1')), new \WP_Term($this->getTermObject(2, 'category 2'))],
                'target' => [new \WP_Term($this->getTermObject(1, 'cat~ego~ry 1')), new \WP_Term($this->getTermObject(2, 'cat~ego~ry 2'))],
            ];
            return [
                [
                    'Should return all terms (no linked submissions in terms)' =>
                    // #0
                    [
                        'source' => [
                            ['label' => 'category 1', 'value' => 1],
                            ['label' => 'category 2', 'value' => 2],
                        ],
                        'target' => [
                            ['label' => 'cat~ego~ry 1', 'value' => 1],
                            ['label' => 'cat~ego~ry 2', 'value' => 2],
                        ],
                    ],
                    $defaultTerms,
                    [$this->getSubmission(3, 3)], // not taxonomy submission
                ],
                [
                    'Should exclude terms with linked source id' =>
                    // #1
                    [
                        'source' => [
                            ['label' => 'category 2', 'value' => 2],
                        ],
                        'target' => [
                            ['label' => 'cat~ego~ry 1', 'value' => 1],
                            ['label' => 'cat~ego~ry 2', 'value' => 2],
                        ],
                    ],
                    $defaultTerms,
                    [$this->getSubmission(1, 3)],
                ],
                [
                    'Should exclude terms with linked target id' =>
                    // #2
                    [
                        'source' => [
                            ['label' => 'category 1', 'value' => 1],
                            ['label' => 'category 2', 'value' => 2],
                        ],
                        'target' => [
                            ['label' => 'cat~ego~ry 2', 'value' => 2],
                        ],
                    ],
                    $defaultTerms,
                    [$this->getSubmission(3, 1)],
                ],
                [
                    'Multiple submissions should exclude every matching entry' =>
                    // #3
                    [
                        'source' => [],
                        'target' => [],
                    ],
                    $defaultTerms,
                    [$this->getSubmission(1, 2), $this->getSubmission(2, 1)],
                ],
            ];
        }

        private function getSubmission($sourceId, $targetId)
        {
            $submission = new SubmissionEntity();
            $submission->setSourceId($sourceId);
            $submission->setTargetId($targetId);

            return $submission;
        }

        private function getSubmissionManager($findResult = [])
        {
            $submissionManager = $this->getMockBuilder(SubmissionManager::class)->disableOriginalConstructor()->getMock();
            $submissionManager->method('find')->willReturn($findResult);

            return $submissionManager;
        }

        private function getTermObject($id, $name, $taxonomy = 'category')
        {
            $return = new \StdClass();
            $return->term_id = $id;
            $return->name = $name;
            $return->taxonomy = $taxonomy;

            return $return;
        }
    }
}

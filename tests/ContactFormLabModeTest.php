<?php

namespace {
    if (! class_exists('GFAPI')) {
        /**
         * Minimal Gravity Forms API double: records what the strategy does
         * and what lab-mode filters answer while a submission runs.
         */
        class GFAPI
        {
            /** @var list<int> */
            public static array $deleted = [];

            /** @var array<string, mixed> */
            public static array $seenDuringSubmit = [];

            public static function get_form(int $id): array
            {
                return ['id' => $id, 'fields' => [['id' => 1, 'type' => 'email'], ['id' => 2, 'type' => 'textarea']]];
            }

            public static function submit_form(int $id, array $values): array
            {
                self::$seenDuringSubmit = [
                    'feeds' => apply_filters('gform_addon_pre_process_feeds', [['id' => 9]], [], []),
                    'post_creation_disabled' => apply_filters('gform_disable_post_creation', false, [], []),
                    'after_submission_registered' => isset($GLOBALS['wp_filter']['gform_after_submission']),
                ];

                return ['is_valid' => true, 'entry_id' => 42];
            }

            public static function delete_entry(int $id): bool
            {
                self::$deleted[] = $id;

                return true;
            }
        }
    }
}

namespace ClockworkCompanion\Tests {

    use ClockworkCompanion\ContactForm\HookSilencer;
    use ClockworkCompanion\ContactForm\StrategyResult;
    use ClockworkCompanion\ContactForm\Strategies\AbstractStrategy;
    use ClockworkCompanion\ContactForm\Strategies\GravityFormsStrategy;
    use ClockworkCompanion\ContactForm\Strategies\WpFormsStrategy;
    use ClockworkCompanion\ContactForm\Tester;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunInSeparateProcess;
    use PHPUnit\Framework\TestCase;

    class ContactFormLabModeTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['wp_test_filters'] = [];
            $GLOBALS['wp_filter'] = [];
            \GFAPI::$deleted = [];
            \GFAPI::$seenDuringSubmit = [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['wp_filter'] = [];
            $GLOBALS['wp_test_filters'] = [];
            parent::tearDown();
        }

        public function testHookSilencerDetachesAndRestoresRegistrations(): void
        {
            $GLOBALS['wp_filter']['wpcf7_submit'] = 'registered-callbacks';
            $silencer = new HookSilencer();

            $silencer->silence(['wpcf7_submit', 'wpcf7_mail_sent']);
            $this->assertArrayNotHasKey('wpcf7_submit', $GLOBALS['wp_filter']);

            $silencer->restore();
            $this->assertSame('registered-callbacks', $GLOBALS['wp_filter']['wpcf7_submit']);
            $this->assertArrayNotHasKey('wpcf7_mail_sent', $GLOBALS['wp_filter']);
        }

        /**
         * If the form plugin threw, the capture and the silenced hooks must
         * still be removed, or every later email in the request is swallowed.
         */
        public function testCaptureAndSilencedHooksAreRemovedWhenTheFormPluginThrows(): void
        {
            $GLOBALS['wp_filter']['wpcf7_submit'] = 'registered-callbacks';
            $tester = new class extends Tester {
                protected function resolveStrategy(string $plugin): ?AbstractStrategy
                {
                    return new class extends AbstractStrategy {
                        protected function integrationHooks(string $formId): array
                        {
                            return ['wpcf7_submit'];
                        }

                        public function submit(string $formId, string $marker, string $mode): StrategyResult
                        {
                            throw new \RuntimeException('plugin blew up');
                        }
                    };
                }
            };

            $result = $tester->run('any', '1', 'CW-TEST', 'lab');

            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('plugin blew up', (string) $result['error']);
            $this->assertSame([], array_values($GLOBALS['wp_test_filters']['pre_wp_mail'] ?? []));
            $this->assertSame('registered-callbacks', $GLOBALS['wp_filter']['wpcf7_submit']);
        }

        public function testWpFormsTestSubmissionsNeverRedirectAndStoreNothingInLabMode(): void
        {
            $formData = ['settings' => [
                'confirmation_type' => 'redirect',
                'confirmations' => [1 => ['type' => 'redirect', 'redirect' => 'https://example.com/thanks'], 2 => ['type' => 'page', 'page' => 12]],
            ]];

            $lab = WpFormsStrategy::testFormData($formData, true);
            $live = WpFormsStrategy::testFormData($formData, false);

            $this->assertSame('message', $lab['settings']['confirmation_type']);
            $this->assertSame('message', $lab['settings']['confirmations'][1]['type']);
            $this->assertSame('message', $lab['settings']['confirmations'][2]['type']);
            $this->assertSame('1', $lab['settings']['disable_entries']);
            $this->assertArrayNotHasKey('disable_entries', $live['settings']);
            $this->assertSame('message', $live['settings']['confirmations'][1]['type']);
        }

        /**
         * Lab mode relied on a Gravity filter that could not be confirmed to
         * exist; the entry was stored and every feed fired.
         */
        public function testGravityLabModeBlocksFeedsAndIntegrationsAndDeletesTheEntry(): void
        {
            $GLOBALS['wp_filter']['gform_after_submission'] = 'crm-integration';
            $strategy = new GravityFormsStrategy();

            $strategy->suppressStorage('lab', '5');
            try {
                $result = $strategy->submit('5', 'CW-TEST', 'lab');
            } finally {
                $strategy->releaseStorage();
            }

            $this->assertTrue($result->accepted);
            $this->assertSame([], \GFAPI::$seenDuringSubmit['feeds']);
            $this->assertTrue(\GFAPI::$seenDuringSubmit['post_creation_disabled']);
            $this->assertFalse(\GFAPI::$seenDuringSubmit['after_submission_registered']);
            $this->assertSame([42], \GFAPI::$deleted);
            $this->assertSame('crm-integration', $GLOBALS['wp_filter']['gform_after_submission']);
            $this->assertSame([], array_values($GLOBALS['wp_test_filters']['gform_addon_pre_process_feeds'] ?? []));
        }

        public function testGravityLiveModeKeepsTheEntryAndFeeds(): void
        {
            $strategy = new GravityFormsStrategy();
            $strategy->suppressStorage('live', '5');
            $strategy->submit('5', 'CW-TEST', 'live');
            $strategy->releaseStorage();

            $this->assertSame([['id' => 9]], \GFAPI::$seenDuringSubmit['feeds']);
            $this->assertSame([], \GFAPI::$deleted);
        }

        /**
         * An SMTP plugin that replaces wp_mail() ignores pre_wp_mail, so lab
         * mode would have sent the synthetic lead for real.
         */
        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function testLabModeIsRefusedWhenWpMailIsReplaced(): void
        {
            eval('function wp_mail($to, $subject, $message, $headers = "", $attachments = []) { $GLOBALS["cwk_real_mail_sent"] = true; return true; }');

            $called = false;
            $tester = new class($called) extends Tester {
                public function __construct(private bool &$called) {}

                protected function resolveStrategy(string $plugin): ?AbstractStrategy
                {
                    $this->called = true;

                    return null;
                }
            };

            $result = $tester->run('any', '1', 'CW-TEST', 'lab');

            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('Lab mode is unavailable', (string) $result['error']);
            $this->assertNotNull(Tester::wpMailReplacedBy());
        }
    }
}

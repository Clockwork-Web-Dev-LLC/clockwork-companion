<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\TwoFactor\EnrollmentNudge;
use PHPUnit\Framework\TestCase;
use WP_User;

class EnrollmentNudgeDismissalTest extends TestCase
{
    private const USER_ID = 61;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_users'] = [self::USER_ID => new WP_User('jane@example.com', self::USER_ID, 'jane', ['editor'])];
        $GLOBALS['wp_test_user_meta'] = [];
        $GLOBALS['wp_test_current_user'] = $GLOBALS['wp_test_users'][self::USER_ID];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wp_test_current_user'] = null;
        parent::tearDown();
    }

    private function banner(): string
    {
        ob_start();
        (new EnrollmentNudge())->maybeShowNudge();

        return (string) ob_get_clean();
    }

    public function testAfterTheDeadlineOnlyAFewPostponementsAreGranted(): void
    {
        EnrollmentNudge::setRequired(self::USER_ID, 0);

        $this->assertStringContainsString('Remind me tomorrow', $this->banner());
        $granted = [];
        for ($i = 0; $i < 5; $i++) {
            $granted[] = EnrollmentNudge::recordDismissal(self::USER_ID);
            // Let the snooze lapse, as a day passing would.
            delete_user_meta(self::USER_ID, '_clockwork_2fa_nudge_dismissed_until');
        }

        $this->assertSame([true, true, true, false, false], $granted);
        $this->assertSame(0, EnrollmentNudge::postponementsLeft(self::USER_ID));

        $banner = $this->banner();
        $this->assertStringNotContainsString('Remind me tomorrow', $banner);
        $this->assertStringContainsString('locked', $banner);
    }

    public function testBeforeTheDeadlineSnoozingIsUnlimitedAndANewDeadlineResetsTheCount(): void
    {
        EnrollmentNudge::setRequired(self::USER_ID, 10);
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(EnrollmentNudge::recordDismissal(self::USER_ID));
        }
        $this->assertSame(3, EnrollmentNudge::postponementsLeft(self::USER_ID));

        EnrollmentNudge::setGraceDeadline(self::USER_ID, 0);
        EnrollmentNudge::recordDismissal(self::USER_ID);
        EnrollmentNudge::recordDismissal(self::USER_ID);
        $this->assertSame(1, EnrollmentNudge::postponementsLeft(self::USER_ID));

        EnrollmentNudge::setGraceDeadline(self::USER_ID, 0);
        $this->assertSame(3, EnrollmentNudge::postponementsLeft(self::USER_ID));
    }
}

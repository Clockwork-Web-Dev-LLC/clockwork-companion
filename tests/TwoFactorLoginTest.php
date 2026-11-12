<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\TwoFactor\LoginInterceptor;
use ClockworkCompanion\TwoFactor\Totp;
use ClockworkCompanion\TwoFactor\UserSettings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_User;

class TwoFactorLoginTest extends TestCase
{
    private const USER_ID = 42;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_users'] = [self::USER_ID => new WP_User('jane@example.com', self::USER_ID, 'jane', ['administrator'])];
        $GLOBALS['wp_test_user_meta'] = [];
        $GLOBALS['wp_test_actions'] = [];
        $GLOBALS['wp_test_did_actions'] = [];
        $GLOBALS['wp_test_auth_cookie_set'] = [];
        $_SERVER['REMOTE_ADDR'] = '192.0.2.77';

        $this->secret = Totp::generateSecret();
        update_user_meta(self::USER_ID, UserSettings::META_SECRET, $this->secret);
        update_user_meta(self::USER_ID, UserSettings::META_ENABLED, '1');
    }

    private function currentCode(): string
    {
        return Totp::code((string) Totp::base32Decode($this->secret), time());
    }

    public function testBadCodeFiresLoginFailedAndCountsPerUserAcrossTokens(): void
    {
        $this->assertSame('bad', LoginInterceptor::verifyCode(self::USER_ID, '000000' === $this->currentCode() ? '111111' : '000000'));

        $this->assertSame(1, did_action('wp_login_failed'));
        $this->assertSame('jane', $GLOBALS['wp_test_did_actions']['wp_login_failed'][0][0]);
        $this->assertInstanceOf(WP_Error::class, $GLOBALS['wp_test_did_actions']['wp_login_failed'][0][1]);
        $this->assertSame(1, get_user_meta(self::USER_ID, LoginInterceptor::META_FAILURES, true)['count']);
    }

    public function testRepeatedBadCodesLockTheAccountEvenWithTheRightCode(): void
    {
        $wrong = '000000' === $this->currentCode() ? '111111' : '000000';
        $outcomes = [];
        for ($i = 0; $i < 10; $i++) {
            // Each correct-password POST would issue a new pending-login
            // token; the per-user counter must not care.
            $outcomes[] = LoginInterceptor::verifyCode(self::USER_ID, $wrong);
        }

        $this->assertSame(array_merge(array_fill(0, 9, 'bad'), ['locked']), $outcomes);
        $this->assertTrue(LoginInterceptor::isLockedOut(self::USER_ID));
        $this->assertSame('locked', LoginInterceptor::verifyCode(self::USER_ID, $this->currentCode()));
        $this->assertSame(10, did_action('wp_login_failed'), 'refused-while-locked attempts are not checked or counted');
    }

    /** Separate process: other tests define REST_REQUEST, which changes this path. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLockedAccountGetsNoNewChallengeToken(): void
    {
        update_user_meta(self::USER_ID, LoginInterceptor::META_LOCKED_UNTIL, time() + 600);

        $refused = (new LoginInterceptor())->maybeChallenge($GLOBALS['wp_test_users'][self::USER_ID]);
        $this->assertInstanceOf(WP_Error::class, $refused);
        $this->assertSame('clockwork_2fa_locked', $refused->get_error_code());
    }

    public function testGoodCodeClearsTheCounterAndCompletingFiresWpLogin(): void
    {
        $wrong = '000000' === $this->currentCode() ? '111111' : '000000';
        LoginInterceptor::verifyCode(self::USER_ID, $wrong);

        $this->assertSame('ok', LoginInterceptor::verifyCode(self::USER_ID, $this->currentCode()));
        $this->assertSame('', get_user_meta(self::USER_ID, LoginInterceptor::META_FAILURES, true));

        LoginInterceptor::completeLogin(self::USER_ID, false);
        $this->assertSame([self::USER_ID], $GLOBALS['wp_test_auth_cookie_set']);
        $this->assertSame(1, did_action('wp_login'));
        $this->assertSame('jane', $GLOBALS['wp_test_did_actions']['wp_login'][0][0]);
        $this->assertSame(self::USER_ID, $GLOBALS['wp_test_did_actions']['wp_login'][0][1]->ID);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testXmlRpcPasswordLoginIsRefusedForEnrolledUser(): void
    {
        define('XMLRPC_REQUEST', true);

        $result = (new LoginInterceptor())->maybeChallenge($GLOBALS['wp_test_users'][self::USER_ID]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('clockwork_2fa_required', $result->get_error_code());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testXmlRpcApplicationPasswordStillWorksForEnrolledUser(): void
    {
        define('XMLRPC_REQUEST', true);
        $user = $GLOBALS['wp_test_users'][self::USER_ID];

        // What core's wp_authenticate_application_password() fires on a match.
        LoginInterceptor::rememberApplicationPassword($user);

        $this->assertSame($user, (new LoginInterceptor())->maybeChallenge($user));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRestPasswordLoginIsRefusedOnlyForEnrolledUsers(): void
    {
        define('REST_REQUEST', true);
        $enrolled = $GLOBALS['wp_test_users'][self::USER_ID];
        $other = new WP_User('john@example.com', 43, 'john', ['administrator']);
        $GLOBALS['wp_test_users'][43] = $other;

        $this->assertInstanceOf(WP_Error::class, (new LoginInterceptor())->maybeChallenge($enrolled));
        $this->assertSame($other, (new LoginInterceptor())->maybeChallenge($other));
    }
}

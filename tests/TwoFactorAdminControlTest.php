<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Admin\Actions\TwoFactorAdminActions;
use ClockworkCompanion\Admin\Layout;
use ClockworkCompanion\Admin\Menu;
use ClockworkCompanion\Admin\Pages\TwoFactorPage;
use ClockworkCompanion\TwoFactor\EnrollmentNudge;
use ClockworkCompanion\TwoFactor\UserSettings;
use ClockworkCompanion\WhiteLabel\WhiteLabel;
use PHPUnit\Framework\TestCase;
use WP_User;

/**
 * Covers the two halves of "an admin manages someone else's 2FA":
 *
 *   1. denialReason() — who may act on whom. This is the security boundary;
 *      the page renders its buttons off the same answer, so a hole here is a
 *      hole in both places at once.
 *   2. EnrollmentNudge eligibility — whether "Require" actually reaches
 *      accounts the automatic agency rule never covered, without dragging
 *      their colleagues in with them.
 *
 * handle() itself isn't exercised: it ends every branch in exit(), which is
 * why the rule it enforces lives in a separate static in the first place.
 */
class TwoFactorAdminControlTest extends TestCase
{
    private const ACTOR_ID = 1;

    private const AGENCY_ADMIN_ID = 2;

    private const CLIENT_ADMIN_ID = 3;

    private const EDITOR_ID = 4;

    private const SUBSCRIBER_ID = 5;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_filters'] = [];
        $GLOBALS['wp_test_user_meta'] = [];
        $GLOBALS['wp_test_current_user_can'] = true;

        $GLOBALS['wp_test_users'] = [
            self::ACTOR_ID => new WP_User('ops@agencyprime.com', self::ACTOR_ID, 'ops-bob', ['administrator'], 'Bob'),
            self::AGENCY_ADMIN_ID => new WP_User('jane@agencyprime.com', self::AGENCY_ADMIN_ID, 'jane', ['administrator'], 'Jane'),
            self::CLIENT_ADMIN_ID => new WP_User('owner@clientshop.com', self::CLIENT_ADMIN_ID, 'owner', ['administrator'], 'Client Owner'),
            self::EDITOR_ID => new WP_User('ed@clientshop.com', self::EDITOR_ID, 'ed', ['editor'], 'Ed'),
            self::SUBSCRIBER_ID => new WP_User('sub@clientshop.com', self::SUBSCRIBER_ID, 'sub', ['subscriber'], 'Sub'),
        ];
        $GLOBALS['wp_test_current_user'] = $GLOBALS['wp_test_users'][self::ACTOR_ID];
    }

    /** Restricts the site to one agency domain, as a white-labelled install would. */
    private function gateToAgencyDomain(): void
    {
        update_option(WhiteLabel::OPTION_KEY, ['agency_email_domains' => '@agencyprime.com']);
    }

    // ---------------------------------------------------------------- access

    public function testAgencyAdminMayManageAnotherAdminAndAnEditor(): void
    {
        $this->gateToAgencyDomain();

        $this->assertNull(TwoFactorAdminActions::denialReason(self::AGENCY_ADMIN_ID));
        $this->assertNull(TwoFactorAdminActions::denialReason(self::CLIENT_ADMIN_ID));
        $this->assertNull(TwoFactorAdminActions::denialReason(self::EDITOR_ID));
    }

    /**
     * The scope the operator chose: every row Team Status renders is
     * actionable, client-domain administrators included — the most likely
     * real use is turning 2FA off for a client admin who lost their phone.
     */
    public function testClientDomainAdminIsManageableEvenThoughTheNudgeNeverCoveredThem(): void
    {
        $this->gateToAgencyDomain();

        $this->assertNull(TwoFactorAdminActions::denialReason(self::CLIENT_ADMIN_ID));
        $this->assertFalse(
            EnrollmentNudge::isEligible(self::CLIENT_ADMIN_ID),
            'Manageable is not the same as automatically nagged.'
        );
    }

    public function testCapabilityIsCheckedOnTheActingUser(): void
    {
        $GLOBALS['wp_test_current_user_can'] = false;

        $this->assertNotNull(TwoFactorAdminActions::denialReason(self::AGENCY_ADMIN_ID));
    }

    /**
     * A client administrator has manage_options, so the capability check
     * alone would let them turn off the agency's 2FA. Agency membership is
     * what actually separates the two.
     */
    public function testClientAdminCannotManageAnyoneWhenAgencyDomainsAreConfigured(): void
    {
        $this->gateToAgencyDomain();
        $GLOBALS['wp_test_current_user'] = $GLOBALS['wp_test_users'][self::CLIENT_ADMIN_ID];

        $this->assertNotNull(TwoFactorAdminActions::denialReason(self::ACTOR_ID));
        $this->assertNotNull(TwoFactorAdminActions::denialReason(self::EDITOR_ID));
    }

    public function testOwnRowIsNotManageableThroughTheCrossUserHandler(): void
    {
        $this->gateToAgencyDomain();

        $this->assertNotNull(TwoFactorAdminActions::denialReason(self::ACTOR_ID));
    }

    public function testRolesOutsideTheTeamRollCallAreRejected(): void
    {
        $this->gateToAgencyDomain();

        $this->assertNotNull(TwoFactorAdminActions::denialReason(self::SUBSCRIBER_ID));
    }

    public function testUnknownAndNonPositiveUserIdsAreRejected(): void
    {
        $this->gateToAgencyDomain();

        $this->assertNotNull(TwoFactorAdminActions::denialReason(999));
        $this->assertNotNull(TwoFactorAdminActions::denialReason(0));
        $this->assertNotNull(TwoFactorAdminActions::denialReason(-1));
    }

    /**
     * The nonce is bound to the target id, so a form rendered for one
     * teammate can't be replayed against another by editing user_id.
     */
    public function testNonceActionIsPerTarget(): void
    {
        $this->assertNotSame(
            TwoFactorAdminActions::nonceAction(self::AGENCY_ADMIN_ID),
            TwoFactorAdminActions::nonceAction(self::EDITOR_ID)
        );
    }

    // ----------------------------------------------------------- enforcement

    /**
     * The whole point of "Require": reach an account the automatic rule
     * never touched.
     */
    public function testRequiringAClientAdminMakesThemEligibleAndImmediatelyEnforced(): void
    {
        $this->gateToAgencyDomain();
        $this->assertFalse(EnrollmentNudge::isEligible(self::CLIENT_ADMIN_ID));

        EnrollmentNudge::setRequired(self::CLIENT_ADMIN_ID);

        $this->assertTrue(EnrollmentNudge::isExplicitlyRequired(self::CLIENT_ADMIN_ID));
        $this->assertTrue(EnrollmentNudge::isEligible(self::CLIENT_ADMIN_ID));
        $this->assertSame(
            0,
            EnrollmentNudge::daysLeftFor(self::CLIENT_ADMIN_ID),
            'A zero-day grace means the redirect-lock bites on the next admin request.'
        );
    }

    /** Requiring one account must not drag their colleagues in with them. */
    public function testRequiringOneUserLeavesEveryoneElseAlone(): void
    {
        $this->gateToAgencyDomain();

        EnrollmentNudge::setRequired(self::CLIENT_ADMIN_ID);

        $this->assertFalse(EnrollmentNudge::isExplicitlyRequired(self::EDITOR_ID));
        $this->assertFalse(EnrollmentNudge::isEligible(self::EDITOR_ID));
    }

    /** Editors have no manage_options, so only the explicit route reaches them. */
    public function testEditorsAreOnlyEverReachedByTheExplicitRoute(): void
    {
        $this->assertFalse(
            EnrollmentNudge::isEligible(self::EDITOR_ID),
            'No agency domains configured, but an editor still lacks manage_options.'
        );

        EnrollmentNudge::setRequired(self::EDITOR_ID);

        $this->assertTrue(EnrollmentNudge::isEligible(self::EDITOR_ID));
    }

    public function testClearingTheRequirementDropsAClientAdminBackOutOfScope(): void
    {
        $this->gateToAgencyDomain();
        EnrollmentNudge::setRequired(self::CLIENT_ADMIN_ID);

        EnrollmentNudge::clearRequired(self::CLIENT_ADMIN_ID);

        $this->assertFalse(EnrollmentNudge::isExplicitlyRequired(self::CLIENT_ADMIN_ID));
        $this->assertFalse(EnrollmentNudge::isEligible(self::CLIENT_ADMIN_ID));
    }

    /**
     * An agency admin stays in scope after un-requiring — they were covered
     * automatically to begin with — but on a fresh default window rather
     * than the expired one the requirement left behind. Without the deadline
     * being cleared too, "stop requiring" would leave them locked out.
     */
    public function testClearingTheRequirementRestoresAFullGraceWindowForAgencyUsers(): void
    {
        $this->gateToAgencyDomain();
        EnrollmentNudge::setRequired(self::AGENCY_ADMIN_ID);
        $this->assertSame(0, EnrollmentNudge::daysLeftFor(self::AGENCY_ADMIN_ID));

        EnrollmentNudge::clearRequired(self::AGENCY_ADMIN_ID);

        $this->assertTrue(EnrollmentNudge::isEligible(self::AGENCY_ADMIN_ID));
        $this->assertGreaterThan(1, EnrollmentNudge::daysLeftFor(self::AGENCY_ADMIN_ID));
    }

    /** Nothing left to nudge an enrolled user about, required or not. */
    public function testAnEnrolledUserIsNeverEligibleEvenWhenFlaggedRequired(): void
    {
        $this->gateToAgencyDomain();
        EnrollmentNudge::setRequired(self::CLIENT_ADMIN_ID);

        update_user_meta(self::CLIENT_ADMIN_ID, UserSettings::META_SECRET, 'JBSWY3DPEHPK3PXP');
        update_user_meta(self::CLIENT_ADMIN_ID, UserSettings::META_ENABLED, '1');

        $this->assertTrue(UserSettings::isEnabled(self::CLIENT_ADMIN_ID));
        $this->assertFalse(EnrollmentNudge::isEligible(self::CLIENT_ADMIN_ID));
    }

    /**
     * The lockout-recovery path, end to end: turning someone's 2FA off wipes
     * every scrap of their setup and does NOT silently re-lock them.
     */
    public function testDisablingWipesTheSetupAndDoesNotLeaveThemRequired(): void
    {
        $this->gateToAgencyDomain();
        update_user_meta(self::CLIENT_ADMIN_ID, UserSettings::META_SECRET, 'JBSWY3DPEHPK3PXP');
        update_user_meta(self::CLIENT_ADMIN_ID, UserSettings::META_ENABLED, '1');
        update_user_meta(self::CLIENT_ADMIN_ID, UserSettings::META_BACKUP_CODES, ['hash']);
        EnrollmentNudge::setRequired(self::CLIENT_ADMIN_ID);

        UserSettings::disable(self::CLIENT_ADMIN_ID);
        EnrollmentNudge::clearRequired(self::CLIENT_ADMIN_ID);

        $this->assertFalse(UserSettings::isEnabled(self::CLIENT_ADMIN_ID));
        $this->assertSame('', UserSettings::secret(self::CLIENT_ADMIN_ID));
        $this->assertSame(0, UserSettings::backupCodesRemaining(self::CLIENT_ADMIN_ID));
        $this->assertFalse(EnrollmentNudge::isExplicitlyRequired(self::CLIENT_ADMIN_ID));
    }

    // ------------------------------------------------------- lockout safety

    /**
     * Requiring an editor only works if the page enforcement locks them to
     * is one they can open. Every other Clockwork page is manage_options;
     * this one has to sit lower or "Require" bricks their wp-admin.
     */
    public function testTheLoginSecurityPageIsReachableBelowManageOptions(): void
    {
        $this->assertNotSame(Menu::CAPABILITY, TwoFactorPage::SELF_CAPABILITY);
        $this->assertSame('read', TwoFactorPage::SELF_CAPABILITY);
    }

    /**
     * ...and once they're there, the chrome must not be a row of links to
     * pages that will 403 them.
     */
    public function testANonAdminOnlySeesTheTabTheyCanOpen(): void
    {
        $GLOBALS['wp_test_current_user_can'] = false;

        $tabs = Layout::tabs();

        $this->assertCount(1, $tabs);
        $this->assertSame('two-factor', $tabs[0]['slug']);
        $this->assertSame(TwoFactorPage::SLUG, $tabs[0]['page']);
    }

    public function testAdminsStillSeeTheFullTabStrip(): void
    {
        $this->assertGreaterThan(1, count(Layout::tabs()));
    }

    /**
     * Reaching the page under the lower capability must not hand anyone the
     * Team Status table or the site-wide WFLS removal button.
     */
    public function testSiteWideCardsStayGatedForUsersBelowTheAdminBar(): void
    {
        $GLOBALS['wp_test_current_user_can'] = false;
        $this->assertFalse(TwoFactorPage::canManageOthers());

        $this->gateToAgencyDomain();
        $GLOBALS['wp_test_current_user_can'] = true;
        $GLOBALS['wp_test_current_user'] = $GLOBALS['wp_test_users'][self::CLIENT_ADMIN_ID];
        $this->assertFalse(
            TwoFactorPage::canManageOthers(),
            'A client admin clears manage_options but not the agency check.'
        );

        $GLOBALS['wp_test_current_user'] = $GLOBALS['wp_test_users'][self::ACTOR_ID];
        $this->assertTrue(TwoFactorPage::canManageOthers());
    }

    /**
     * The rescue hatch has to win over an explicit requirement too —
     * otherwise CLOCKWORK_2FA_DISABLE would still leave required users
     * redirect-locked to a page whose gate isn't running.
     */
    public function testTheDisableHatchBeatsAnExplicitRequirement(): void
    {
        $this->gateToAgencyDomain();
        EnrollmentNudge::setRequired(self::CLIENT_ADMIN_ID);

        $GLOBALS['wp_test_filters']['clockwork_companion_2fa_disabled'] = [
            static fn ($value) => true,
        ];

        $this->assertFalse(EnrollmentNudge::isEligible(self::CLIENT_ADMIN_ID));
    }
}

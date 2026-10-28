<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

/**
 * The markup helpers the Setup family renders through on the v2 shell
 * (tracking202/setup/_includes/setup_ui.php).
 *
 * The Setup handlers kept their own sentences, stored as the classic
 * `<div class="error">…</div>` strings and sometimes several to a key; these
 * pin that each sentence reaches the user as text, under its field, once,
 * escaped — and that the option builder cannot fold two categories that
 * share a name into one group (error pattern #17).
 */
final class SetupUiHelpersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-ui.php';
        require_once dirname(__DIR__, 2) . '/tracking202/setup/_includes/setup_ui.php';
    }

    public function testAStoredClassicErrorBecomesItsSentence(): void
    {
        self::assertSame('Give this landing page a nickname', p202_setup_error_text('<div class="error">Give this landing page a nickname</div>'));
        self::assertSame(
            'What is the URL of your landing page? Your Landing Page URL must start with http:// or https://',
            p202_setup_error_text('<div class="error">What is the URL of your landing page?</div><div class="error">Your Landing Page URL must start with http:// or https://</div>'),
            'two sentences appended to one key read as two sentences'
        );
        self::assertSame("Type in the name of your campaign's category.", p202_setup_error_text('<div class="error">Type in the name of your campaign\'s category.</div>'));
        self::assertSame('Plain already.', p202_setup_error_text('Plain already.'));
        self::assertSame('', p202_setup_error_text(''));
        self::assertSame('', p202_setup_error_text(null));
    }

    public function testFeedbackSitsUnderTheFieldEscapedAndOnlyWhenThereIsAnError(): void
    {
        $errors = ['landing_page_url' => '<div class="error">Must start with <http></div>', 'other' => ''];
        self::assertSame(' is-invalid', p202_setup_invalid($errors, 'landing_page_url'));
        self::assertSame('', p202_setup_invalid($errors, 'other'), 'an empty stored error is no error');
        self::assertSame('', p202_setup_invalid($errors, 'missing'));
        self::assertSame(
            '<div class="invalid-feedback d-block">Must start with &lt;http&gt;</div>',
            p202_setup_feedback($errors, 'landing_page_url'),
            'the sentence is escaped: a stored error can carry what the user typed'
        );
        self::assertSame('', p202_setup_feedback($errors, 'other', 'missing'));
    }

    public function testErrorsWithoutAFieldAreFlashedAndFieldErrorsAreCounted(): void
    {
        $html = p202_setup_error_flashes([
            'token' => '<div class="error">Invalid or expired form token. Please reload the page and try again.</div>',
            'aff_campaign_payout' => '<div class="error">Please enter in a numeric number for the payout.</div>',
            'aff_campaign_url' => '<div class="error">Your Landing Page URL must start with http:// or https://</div>',
            'unused' => '',
        ], ['aff_campaign_payout', 'aff_campaign_url']);
        self::assertStringContainsString('2 fields need attention', $html);
        self::assertStringContainsString('Invalid or expired form token', $html, 'the token refusal has no field, so it is flashed');
        self::assertStringNotContainsString('numeric number', $html, 'a field\'s sentence goes under the field, not in a flash');
        self::assertSame(2, substr_count($html, 'p202-flash__body'), 'two flashes: the count and the token');

        $orphan = p202_setup_error_flashes(['wrong_user' => 'You are not authorized to modify another users campaign'], ['aff_campaign_name']);
        self::assertStringContainsString('You are not authorized', $orphan, 'an error keyed by no rendered field is never silent');
    }

    public function testOptionsGroupByIdSoTwoCategoriesWithOneNameStayTwo(): void
    {
        $html = p202_setup_options([
            'n1' => ['label' => 'Acme', 'options' => ['11' => ['label' => 'Offer A', 'data' => ['network' => '1']]]],
            'n2' => ['label' => 'Acme', 'options' => ['21' => ['label' => 'Offer B', 'data' => ['network' => '2']]]],
        ], '21', 'Choose a campaign');
        self::assertSame(2, substr_count($html, '<optgroup label="Acme">'), 'two categories named alike are two groups');
        self::assertStringContainsString('<option value="21" data-network="2" selected>Offer B</option>', $html);
        self::assertStringContainsString('<option value="">Choose a campaign</option>', $html);
        self::assertStringNotContainsString('value="11" data-network="1" selected', $html);

        $flat = p202_setup_options(['0' => 'Off by default', '1' => 'On by default'], 1);
        self::assertStringContainsString('<option value="1" selected>On by default</option>', $flat, 'an int selects its string value');
        self::assertStringContainsString('&lt;b&gt;', p202_setup_options(['x' => '<b>'], null), 'labels are escaped');
    }

    public function testTheOnlyOptionIsChosenAndTwoAreNot(): void
    {
        self::assertSame('11', p202_setup_only_option(['n1' => ['label' => 'Acme', 'options' => ['11' => 'A']]]));
        self::assertNull(p202_setup_only_option(['n1' => ['label' => 'Acme', 'options' => ['11' => 'A']], 'n2' => ['label' => 'B', 'options' => ['21' => 'B']]]));
        self::assertSame('7', p202_setup_only_option(['7' => 'Seven']));
        self::assertNull(p202_setup_only_option([]));
    }

    public function testRemoveKeepsTheGetRequestTheHandlerReadsAndAsksFirst(): void
    {
        $html = p202_setup_remove_form('/tracking202/setup/aff_networks.php', [
            'delete_aff_network_id' => 7,
            'delete_aff_network_name' => 'A "quoted" name',
            'token' => 'abc',
        ], 'Remove "A"?');
        self::assertStringContainsString('method="get"', $html);
        self::assertStringContainsString('data-p202-confirm="Remove &quot;A&quot;?"', $html);
        self::assertStringContainsString('<input type="hidden" name="delete_aff_network_id" value="7">', $html);
        self::assertStringContainsString('value="A &quot;quoted&quot; name"', $html);
        self::assertStringContainsString('<input type="hidden" name="token" value="abc">', $html);
    }

    public function testTheCodeBoxCopiesExactlyWhatItShows(): void
    {
        $snippet = "<script>alert('x')</script>\n&amp;";
        $html = p202_setup_code_box($snippet, ['label' => 'Pixel', 'id' => 'px', 'long' => true]);
        self::assertStringContainsString('<pre class="p202-code__value p202-code__value--long" id="px">&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;' . "\n" . '&amp;amp;</pre>', $html);
        self::assertStringContainsString('data-p202-copy="&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;' . "\n" . '&amp;amp;"', $html);
        self::assertStringContainsString('<div class="form-label">Pixel</div>', $html);
    }

    public function testQueryFlashesOnlyForTheirOwnKey(): void
    {
        $html = p202_setup_query_flashes(['added' => 'Added.', 'deleted' => 'Removed.'], ['added' => '1', 'deleted' => 'yes']);
        self::assertStringContainsString('Added.', $html);
        self::assertStringNotContainsString('Removed.', $html, 'only the value 1 raises a flash');
    }
}

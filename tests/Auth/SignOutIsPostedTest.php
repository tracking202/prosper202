<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * The sign-out page signs out only on a POST carrying the session's token: a
 * GET signed out, so a link or an image on any other site could sign a person
 * out. The page is reached only through p202_signout_form(), so nothing in the
 * served tree names it but that helper: no link, no redirect, no script that
 * opens it by GET (clickservers.php submits the chrome's form).
 *
 * The scan reads the page's file name as written, outside comments. A name
 * assembled from parts ('signout' . '.php') is not seen. What the page does
 * with a GET or a POST without the token is
 * RememberMeKeysRevokedIntegrationTest's, run as the web server runs it.
 */
final class SignOutIsPostedTest extends TestCase
{
    private const HELPER_FILE = '202-config/functions-ui.php';

    /** Served files other than PHP that can hold a link or a script. */
    private const OTHER_EXTENSIONS = ['js', 'html', 'htm'];

    public function testOnlyTheSignOutFormNamesTheSignOutPage(): void
    {
        $found = [];
        $files = SourceScan::phpFiles();
        self::assertGreaterThan(500, count($files), 'the walk read the served tree');
        foreach ($files as $path => $source) {
            $count = substr_count(strtolower(self::withoutComments($source)), 'signout.php');
            if ($count > 0) {
                $found[$path] = $count;
            }
        }
        foreach (self::otherServedFiles() as $path => $source) {
            $count = substr_count(strtolower($source), 'signout.php');
            if ($count > 0) {
                $found[$path] = $count;
            }
        }
        self::assertSame(
            [self::HELPER_FILE => 1],
            $found,
            'Only p202_signout_form() names 202-account/signout.php. A link, a redirect or a script that opens'
            . ' the page by GET signs nobody out now; draw the form with p202_signout_form(), or submit the'
            . " chrome's (form[data-p202-signout]) from a script."
        );
    }

    public function testTheFormPostsTheSessionsTokenToTheSignOutPage(): void
    {
        require_once SourceScan::repoRoot() . '/' . self::HELPER_FILE;
        $html = p202_signout_form(
            'https://t.example/',
            'tok"en<1>',
            'Sign Out',
            'p202c-nav__link',
            'p202c-signout',
            'SignoutPage',
            'exit'
        );

        $form = self::onlyForm($html);
        self::assertSame('post', strtolower($form->getAttribute('method')));
        self::assertSame('https://t.example/202-account/signout.php', $form->getAttribute('action'));
        self::assertSame('p202c-signout', $form->getAttribute('class'));
        self::assertTrue($form->hasAttribute('data-p202-signout'), 'the mark clickservers.php submits by');

        $inputs = $form->getElementsByTagName('input');
        self::assertSame(1, $inputs->length);
        $token = $inputs->item(0);
        self::assertInstanceOf(\DOMElement::class, $token);
        self::assertSame(['hidden', 'token', 'tok"en<1>'], [
            $token->getAttribute('type'), $token->getAttribute('name'), $token->getAttribute('value'),
        ]);

        $buttons = $form->getElementsByTagName('button');
        self::assertSame(1, $buttons->length);
        $button = $buttons->item(0);
        self::assertInstanceOf(\DOMElement::class, $button);
        self::assertSame(['submit', 'p202c-nav__link', 'SignoutPage', 'Sign Out'], [
            $button->getAttribute('type'), $button->getAttribute('class'), $button->getAttribute('id'),
            trim($button->textContent),
        ]);
        self::assertSame(1, $button->getElementsByTagName('svg')->length, 'the icon beside the label');
    }

    /**
     * A session with no usable token posts an empty one, which the page
     * refuses with the button again; never a value that is not the token.
     *
     * @dataProvider unusableTokens
     */
    public function testATokenThatIsNotAStringPostsAsNone(mixed $token): void
    {
        require_once SourceScan::repoRoot() . '/' . self::HELPER_FILE;
        $form = self::onlyForm(p202_signout_form('', $token, 'Sign out', 'btn btn-primary'));
        $input = $form->getElementsByTagName('input')->item(0);
        self::assertInstanceOf(\DOMElement::class, $input);
        self::assertSame('', $input->getAttribute('value'));
        self::assertSame('202-account/signout.php', $form->getAttribute('action'));
    }

    /** @return array<string, array{mixed}> */
    public static function unusableTokens(): array
    {
        return ['none' => [null], 'a list' => [['a']], 'a number' => [12]];
    }

    private static function onlyForm(string $html): \DOMElement
    {
        $doc = new \DOMDocument();
        self::assertTrue(@$doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>'));
        $forms = $doc->getElementsByTagName('form');
        self::assertSame(1, $forms->length, $html);
        $form = $forms->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);

        return $form;
    }

    private static function withoutComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /** @return array<string, string> repo-relative path => contents */
    private static function otherServedFiles(): array
    {
        $root = SourceScan::repoRoot();
        $pruned = array_merge(SourceScan::PRUNED, ['tests']);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                SourceScan::tree($root),
                static fn (\SplFileInfo $file): bool => !in_array($file->getFilename(), $pruned, true)
            )
        );
        $files = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || !in_array(strtolower($file->getExtension()), self::OTHER_EXTENSIONS, true)) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                throw new \RuntimeException('Could not read ' . $file->getPathname());
            }
            $files[substr($file->getPathname(), strlen($root) + 1)] = $source;
        }

        return $files;
    }
}

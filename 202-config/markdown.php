<?php

declare(strict_types=1);

/*
 * The Markdown renderer behind 202-account/docs.php.
 *
 * Its own file so tests/Api/V3/MarkdownRendererTest.php can exercise it:
 * docs.php authenticates and renders a page when it is included, so the
 * function could not be reached from a test while it lived there.
 */

if (!function_exists('markdownToHtml')) {

    /**
     * Render the subset of Markdown the documents under documentation/ use.
     *
     * Not a general Markdown parser, and not meant to become one: headings,
     * fenced and inline code, links, bold, italic, bulleted and numbered lists,
     * pipe tables and paragraphs. Everything it does not know is emitted as
     * plain text rather than as its markup characters.
     *
     * Code is protected first, as placeholders, so the inline rules never run
     * inside it — without that, two `code` spans on one line, each ending in an
     * asterisk, pair up into an <em> and swallow the text between them.
     */
    function markdownToHtml(string $markdown): string {
        $protected = [];
        $protect = static function (string $html) use (&$protected): string {
            $key = "\x02P" . count($protected) . "\x03";
            $protected[$key] = $html;
            return $key;
        };

        // Fenced code blocks, before anything else touches their contents.
        $html = (string) preg_replace_callback('/```([A-Za-z0-9_+-]*)\r?\n(.*?)```/s', static function (array $m) use ($protect): string {
            $language = $m[1] !== '' ? ' class="language-' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '"' : '';
            return $protect('<pre><code' . $language . '>' . htmlspecialchars(rtrim($m[2], "\r\n"), ENT_NOQUOTES, 'UTF-8') . '</code></pre>');
        }, $markdown);

        // Headers.
        $html = (string) preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = (string) preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
        $html = (string) preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);

        // Inline code, also protected: it may contain asterisks, brackets and pipes.
        $html = (string) preg_replace_callback('/`([^`\n]+)`/', static function (array $m) use ($protect): string {
            return $protect('<code>' . htmlspecialchars($m[1], ENT_NOQUOTES, 'UTF-8') . '</code>');
        }, $html);

        $html = (string) preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $html);
        $html = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html);
        $html = (string) preg_replace('/\*([^*\n]+)\*/', '<em>$1</em>', $html);

        $lines = explode("\n", $html);
        $count = count($lines);
        $out = [];
        $paragraph = '';
        $cells = static fn (string $row): array => array_map('trim', explode('|', trim(trim($row), '|')));
        $flush = static function () use (&$paragraph, &$out): void {
            if ($paragraph !== '') {
                $out[] = '<p>' . $paragraph . '</p>';
                $paragraph = '';
            }
        };
        // A list item's continuation lines are indented; fold them into the item.
        $gather = static function (int &$i) use ($lines, $count): string {
            $text = '';
            while ($i + 1 < $count && trim($lines[$i + 1]) !== ''
                && preg_match('/^\s+\S/', $lines[$i + 1]) === 1
                && preg_match('/^\s*(?:[-*+]\s|\d+\.\s)/', $lines[$i + 1]) !== 1) {
                $i++;
                $text .= ' ' . trim($lines[$i]);
            }
            return $text;
        };

        for ($i = 0; $i < $count; $i++) {
            $line = trim($lines[$i]);

            if ($line === '') {
                $flush();
                continue;
            }

            // A pipe table: a header row, a row of dashes, then the body rows.
            if (str_starts_with($line, '|') && isset($lines[$i + 1]) && preg_match('/^\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/', trim($lines[$i + 1])) === 1) {
                $flush();
                $table = '<table class="doc-table"><thead><tr>';
                foreach ($cells($line) as $cell) {
                    $table .= '<th>' . $cell . '</th>';
                }
                $table .= '</tr></thead><tbody>';
                for ($i += 2; $i < $count && str_starts_with(trim($lines[$i]), '|'); $i++) {
                    $table .= '<tr>';
                    foreach ($cells($lines[$i]) as $cell) {
                        $table .= '<td>' . $cell . '</td>';
                    }
                    $table .= '</tr>';
                }
                $i--; // the loop increment steps past the last row
                $out[] = $table . '</tbody></table>';
                continue;
            }

            // Lists. A blank line between items keeps the same list open, as in
            // the documents; anything else closes it.
            if (preg_match('/^([-*+]|\d+\.)\s+(.*)$/', $line, $match) === 1) {
                $flush();
                $ordered = !in_array($match[1], ['-', '*', '+'], true);
                $items = '';
                while ($i < $count) {
                    $current = trim($lines[$i]);
                    if ($current === '') {
                        // Peek past the blank line: another item continues the list.
                        $next = $i + 1;
                        while ($next < $count && trim($lines[$next]) === '') {
                            $next++;
                        }
                        if ($next < $count && preg_match('/^\s*([-*+]|\d+\.)\s/', $lines[$next]) === 1
                            && ($ordered === (preg_match('/^\s*\d+\.\s/', $lines[$next]) === 1))) {
                            $i = $next;
                            continue;
                        }
                        break;
                    }
                    if (preg_match('/^([-*+]|\d+\.)\s+(.*)$/', $current, $item) !== 1
                        || $ordered !== !in_array($item[1], ['-', '*', '+'], true)) {
                        break;
                    }
                    $items .= '<li>' . $item[2] . $gather($i) . '</li>';
                    $i++;
                }
                $i--; // the loop increment steps past the line that ended the list
                $out[] = ($ordered ? '<ol>' : '<ul>') . $items . ($ordered ? '</ol>' : '</ul>');
                continue;
            }

            // Block-level HTML produced above (headings, protected code) stands alone.
            if (preg_match('/^(<(?:h[1-6]|pre|table|ul|ol)\b|\x02P\d+\x03$)/', $line) === 1) {
                $flush();
                $out[] = $line;
                continue;
            }

            $paragraph .= ($paragraph === '' ? '' : ' ') . $line;
        }
        $flush();

        return strtr(implode("\n", $out), $protected);
    }
}

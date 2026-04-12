<?php

function wallos_markdown_escape($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function wallos_markdown_render_inline($text)
{
    $escaped = wallos_markdown_escape($text);

    $codePlaceholders = [];
    $escaped = preg_replace_callback('/`([^`\n]+)`/', function ($matches) use (&$codePlaceholders) {
        $placeholder = '[[WALLOS_MD_CODE_' . count($codePlaceholders) . ']]';
        $codePlaceholders[$placeholder] = '<code>' . $matches[1] . '</code>';
        return $placeholder;
    }, $escaped);

    $linkPlaceholders = [];
    $escaped = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($matches) use (&$linkPlaceholders) {
        $label = $matches[1];
        $url = html_entity_decode($matches[2], ENT_QUOTES, 'UTF-8');
        $url = trim($url);

        if ($url === '' || !preg_match('/^https?:\/\//i', $url)) {
            return $label;
        }

        $placeholder = '[[WALLOS_MD_LINK_' . count($linkPlaceholders) . ']]';
        $linkPlaceholders[$placeholder] = '<a href="' . wallos_markdown_escape($url) . '" target="_blank" rel="noreferrer">' . $label . '</a>';
        return $placeholder;
    }, $escaped);

    $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
    $escaped = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $escaped);
    $escaped = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $escaped);
    $escaped = preg_replace('/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/s', '<em>$1</em>', $escaped);

    foreach ($codePlaceholders as $placeholder => $replacement) {
        $escaped = str_replace($placeholder, $replacement, $escaped);
    }

    foreach ($linkPlaceholders as $placeholder => $replacement) {
        $escaped = str_replace($placeholder, $replacement, $escaped);
    }

    return $escaped;
}

function wallos_markdown_render_blocks($markdown)
{
    $markdown = str_replace(["\r\n", "\r"], "\n", (string) $markdown);
    $markdown = trim($markdown);

    if ($markdown === '') {
        return '';
    }

    $parts = preg_split('/(```[\s\S]*?```)/', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $html = [];

    foreach ($parts as $part) {
        if (preg_match('/^```([\s\S]*?)```$/', $part, $matches)) {
            $code = trim($matches[1], "\n");
            $html[] = '<pre><code>' . wallos_markdown_escape($code) . '</code></pre>';
            continue;
        }

        $lines = explode("\n", $part);
        $paragraphLines = [];
        $listType = null;
        $listItems = [];
        $blockquoteLines = [];

        $flushParagraph = function () use (&$paragraphLines, &$html) {
            if (empty($paragraphLines)) {
                return;
            }

            $content = wallos_markdown_render_inline(implode("\n", $paragraphLines));
            $html[] = '<p>' . nl2br($content, false) . '</p>';
            $paragraphLines = [];
        };

        $flushList = function () use (&$listType, &$listItems, &$html) {
            if ($listType === null || empty($listItems)) {
                return;
            }

            $itemsHtml = array_map(function ($item) {
                return '<li>' . wallos_markdown_render_inline($item) . '</li>';
            }, $listItems);

            $html[] = '<' . $listType . '>' . implode('', $itemsHtml) . '</' . $listType . '>';
            $listType = null;
            $listItems = [];
        };

        $flushBlockquote = function () use (&$blockquoteLines, &$html) {
            if (empty($blockquoteLines)) {
                return;
            }

            $html[] = '<blockquote>' . wallos_markdown_render_blocks(implode("\n", $blockquoteLines)) . '</blockquote>';
            $blockquoteLines = [];
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $flushParagraph();
                $flushList();
                $flushBlockquote();
                continue;
            }

            if (preg_match('/^\s*>\s?(.*)$/', $line, $matches)) {
                $flushParagraph();
                $flushList();
                $blockquoteLines[] = $matches[1];
                continue;
            }

            $flushBlockquote();

            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $matches)) {
                $flushParagraph();
                $flushList();
                $level = strlen($matches[1]);
                $html[] = '<h' . $level . '>' . wallos_markdown_render_inline($matches[2]) . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/^([-*+])\s+(.+)$/', $trimmed, $matches)) {
                $flushParagraph();
                if ($listType !== 'ul') {
                    $flushList();
                    $listType = 'ul';
                }
                $listItems[] = $matches[2];
                continue;
            }

            if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $matches)) {
                $flushParagraph();
                if ($listType !== 'ol') {
                    $flushList();
                    $listType = 'ol';
                }
                $listItems[] = $matches[1];
                continue;
            }

            if ($listType !== null) {
                $flushList();
            }

            $paragraphLines[] = $line;
        }

        $flushParagraph();
        $flushList();
        $flushBlockquote();
    }

    return implode('', $html);
}

function wallos_render_markdown($markdown)
{
    return wallos_markdown_render_blocks($markdown);
}

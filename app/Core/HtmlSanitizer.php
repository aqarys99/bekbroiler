<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Media;
use DOMDocument;
use DOMElement;
use DOMNode;

/** Единственная граница безопасности для HTML из редактора (I-30). Белый список, идемпотентно. */
final class HtmlSanitizer
{
    private const ALLOWED = ['p', 'br', 'strong', 'em', 'h2', 'ul', 'ol', 'li', 'a', 'img'];
    private const RENAME = ['b' => 'strong', 'i' => 'em', 'div' => 'p', 'h1' => 'h2', 'h3' => 'h2', 'h4' => 'h2', 'h5' => 'h2', 'h6' => 'h2'];
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'video', 'audio', 'form', 'input', 'textarea',
        'select', 'button', 'svg', 'math', 'template', 'noscript', 'head', 'title', 'meta', 'link'];

    /** @var array<int,bool> кэш проверки существования фото в рамках вызова */
    private array $imageOk = [];

    public static function clean(string $html): string
    {
        if (!class_exists(DOMDocument::class)) {
            throw new \RuntimeException('Расширение DOM не включено');
        }
        return (new self())->run($html);
    }

    private function run(string $html): string
    {
        $html = str_replace("\0", '', $html);
        if (trim($html) === '') {
            return '';
        }
        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><html><body><div id="__root">' . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('__root');
        if ($root === null) {
            foreach ($doc->getElementsByTagName('div') as $d) {
                if ($d->getAttribute('id') === '__root') {
                    $root = $d;
                    break;
                }
            }
        }
        if ($root === null) {
            return '';
        }
        $this->walk($root);
        $this->trimTrailingEmpty($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        $out = trim($out);
        // тело без текста и без img -> ''
        if (!preg_match('/<img\b/i', $out) && trim(html_entity_decode(strip_tags($out), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\xC2\xA0") === '') {
            return '';
        }
        return $out;
    }

    private function walk(DOMNode $node): void
    {
        $children = [];
        foreach ($node->childNodes as $c) {
            $children[] = $c;
        }
        foreach ($children as $child) {
            if (!$child instanceof DOMElement) {
                if ($child->nodeType !== XML_TEXT_NODE && $child->nodeType !== XML_CDATA_SECTION_NODE) {
                    $node->removeChild($child); // комментарии, PI
                }
                continue;
            }
            $name = strtolower($child->nodeName);
            if (in_array($name, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            if (isset(self::RENAME[$name])) {
                $child = $this->rename($child, self::RENAME[$name]);
                $name = $child->nodeName;
            }
            if (!in_array($name, self::ALLOWED, true)) {
                $this->walk($child);
                $this->unwrap($child);
                continue;
            }
            if ($name === 'a') {
                $href = $this->safeHref($child->getAttribute('href'));
                if ($href === null) {
                    $this->walk($child);
                    $this->unwrap($child);
                    continue;
                }
                $this->stripAttrs($child, []);
                $child->setAttribute('href', $href);
                if (preg_match('~^https?:~i', $href)) {
                    $child->setAttribute('target', '_blank');
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
                $this->walk($child);
                continue;
            }
            if ($name === 'img') {
                $id = ctype_digit(trim($child->getAttribute('data-media-id'))) ? (int)trim($child->getAttribute('data-media-id')) : 0;
                if ($id <= 0 || !$this->imageExists($id)) {
                    $node->removeChild($child);
                    continue;
                }
                $this->stripAttrs($child, []);
                $child->setAttribute('data-media-id', (string)$id);
                continue;
            }
            $this->stripAttrs($child, []);
            if ($name !== 'br') {
                $this->walk($child);
            }
        }
    }

    private function rename(DOMElement $el, string $to): DOMElement
    {
        $new = $el->ownerDocument->createElement($to);
        while ($el->firstChild !== null) {
            $new->appendChild($el->firstChild);
        }
        $el->parentNode->replaceChild($new, $el);
        return $new;
    }

    private function unwrap(DOMElement $el): void
    {
        $parent = $el->parentNode;
        while ($el->firstChild !== null) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }

    /** Удалить все атрибуты, кроме перечисленных. */
    private function stripAttrs(DOMElement $el, array $keep): void
    {
        $names = [];
        foreach ($el->attributes as $a) {
            $names[] = $a->nodeName;
        }
        foreach ($names as $n) {
            if (!in_array($n, $keep, true)) {
                $el->removeAttribute($n);
            }
        }
    }

    private function safeHref(string $href): ?string
    {
        // пробелы и управляющие символы убираем целиком: «java\tscript:» не должен обходить проверку схемы
        $href = (string)preg_replace('/[\x00-\x20\x7f]+/', '', $href);
        return $href !== '' && preg_match('~^(https?:|mailto:)~i', $href) === 1 ? $href : null;
    }

    private function imageExists(int $id): bool
    {
        if (!array_key_exists($id, $this->imageOk)) {
            $row = Media::find($id);
            $this->imageOk[$id] = $row !== null && $row['type'] === 'image';
        }
        return $this->imageOk[$id];
    }

    private function trimTrailingEmpty(DOMElement $root): void
    {
        while ($root->lastChild !== null) {
            $last = $root->lastChild;
            if ($last->nodeType === XML_TEXT_NODE && trim($last->textContent, " \t\n\r\0\x0B\xC2\xA0") === '') {
                $root->removeChild($last);
                continue;
            }
            if ($last instanceof DOMElement && $last->nodeName === 'p' && !$this->hasContent($last)) {
                $root->removeChild($last);
                continue;
            }
            break;
        }
    }

    private function hasContent(DOMElement $p): bool
    {
        if (trim($p->textContent, " \t\n\r\0\x0B\xC2\xA0") !== '') {
            return true;
        }
        return $p->getElementsByTagName('img')->length > 0;
    }
}

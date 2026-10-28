<?php

namespace App\Services;

use App\Support\TemplateFields;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class DocxTemplate
{
    private const Namespace = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function inspect(string $path): array
    {
        $zip = $this->open($path);
        try {
            $total = 0;
            $text = [];
            $tokens = [];
            $names = [];
            if ($zip->numFiles > 3000) {
                $this->invalid('Plik DOCX zawiera zbyt wiele elementów.');
            }
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('word/document.xml') === false) {
                $this->invalid('Plik nie jest poprawnym dokumentem DOCX.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $name = $entry['name'];
                if (isset($names[$name])) {
                    $this->invalid('DOCX zawiera powtarzające się elementy. Zapisz plik ponownie w Wordzie.');
                }
                $names[$name] = true;
                $total += $entry['size'];
                if ($total > 50000000 || str_starts_with($name, '/') || str_contains($name, '../') || str_contains($name, '\\') || preg_match('~(?:vbaProject|word/embeddings/|word/activeX/|\.exe$|\.js$|\.html?$)~i', $name)) {
                    $this->invalid('DOCX zawiera osadzone programy, niebezpieczne ścieżki lub zbyt dużo danych.');
                }
                if (! preg_match('/\.(?:xml|rels)$/i', $name)) {
                    continue;
                }
                if ($entry['size'] > 5000000) {
                    $this->invalid('Struktura dokumentu DOCX jest zbyt duża.');
                }
                $xml = $zip->getFromIndex($index);
                $document = $this->xml($xml);
                if (preg_match('~(?:macroEnabled|vbaProject|attachedTemplate)~i', $xml)) {
                    $this->invalid('Zapisz wzór jako DOCX bez makr i zewnętrznego szablonu.');
                }
                $xpath = new DOMXPath($document);
                foreach ($xpath->query('//*[local-name()="Relationship"][@TargetMode="External"]') as $relationship) {
                    if (! str_ends_with($relationship->getAttribute('Type'), '/hyperlink') || ! preg_match('~^(?:https?://|mailto:)~i', $relationship->getAttribute('Target'))) {
                        $this->invalid('DOCX odwołuje się do zewnętrznych plików lub obrazów. Osadź je we wzorze.');
                    }
                }
                if (! $this->isTextPart($name)) {
                    continue;
                }
                $xpath->registerNamespace('w', self::Namespace);
                if ($xpath->query('//w:altChunk | //w:del | //w:ins')->length) {
                    $this->invalid('Przed wgraniem zaakceptuj śledzone zmiany i usuń osadzone dokumenty.');
                }
                foreach ($xpath->query('//w:instrText | //w:fldSimple/@w:instr') as $instruction) {
                    if (preg_match('/\b(?:INCLUDETEXT|INCLUDEPICTURE|DDE|DDEAUTO|LINK|DATABASE|IMPORT|MERGEFIELD)\b/i', $instruction->textContent)) {
                        $this->invalid('Zamień aktywne pola Word na pola Kadrelio lub DocuGen.');
                    }
                }
                foreach ($xpath->query('//w:p') as $paragraph) {
                    $paragraphText = implode('', array_map(fn (DOMElement $node): string => $node->textContent, $this->textNodes($xpath, $paragraph)));
                    $text[] = $paragraphText;
                    $tokens = [...$tokens, ...TemplateFields::tokens($paragraphText)];
                }
            }
            $body = implode("\n", $text);
            if (mb_strlen($body) > 150000) {
                $this->invalid('Dokument zawiera więcej niż 150 000 znaków.');
            }
            $remaining = str_replace($tokens, '', $body);
            if (str_contains($remaining, '{{') || str_contains($remaining, '<<')) {
                $this->invalid('Wzór ma niedomknięte pole lub pole podzielone między akapity. Popraw je w Wordzie.');
            }

            return ['body' => $body, 'placeholders' => array_values(array_unique($tokens))];
        } finally {
            $zip->close();
        }
    }

    public function fill(string $source, string $destination, array $replacements): void
    {
        $this->inspect($source);
        if (! copy($source, $destination)) {
            throw new \RuntimeException('Nie można przygotować kopii wzoru DOCX.');
        }
        $zip = $this->open($destination);
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (! $this->isTextPart($name)) {
                    continue;
                }
                $document = $this->xml($zip->getFromIndex($index));
                $xpath = new DOMXPath($document);
                $xpath->registerNamespace('w', self::Namespace);
                foreach ($xpath->query('//w:p') as $paragraph) {
                    $nodes = $this->textNodes($xpath, $paragraph);
                    $lengths = array_map(fn (DOMElement $node): int => strlen($node->textContent), $nodes);
                    $text = implode('', array_map(fn (DOMElement $node): string => $node->textContent, $nodes));
                    preg_match_all(TemplateFields::Pattern, $text, $matches, PREG_OFFSET_CAPTURE);
                    foreach (array_reverse($matches[0]) as [$token, $start]) {
                        if (! array_key_exists($token, $replacements)) {
                            $this->invalid('Nie przypisano danych do pola '.$token.'.');
                        }
                        $end = $start + strlen($token);
                        $offset = 0;
                        $inserted = false;
                        foreach ($nodes as $nodeIndex => $node) {
                            $originalLength = $lengths[$nodeIndex];
                            $nodeEnd = $offset + $originalLength;
                            if ($nodeEnd > $start && $offset < $end) {
                                $prefix = substr($node->textContent, 0, max(0, $start - $offset));
                                $suffix = $end < $nodeEnd ? substr($node->textContent, $end - $offset) : '';
                                $node->textContent = $prefix.($inserted ? '' : $replacements[$token]).$suffix;
                                $node->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                                $inserted = true;
                            }
                            $offset = $nodeEnd;
                        }
                    }
                    foreach ($nodes as $node) {
                        if (str_contains($node->textContent, "\n")) {
                            $parts = explode("\n", str_replace("\r", '', $node->textContent));
                            $node->textContent = array_shift($parts);
                            $previous = $node;
                            foreach ($parts as $part) {
                                $break = $document->createElementNS(self::Namespace, 'w:br');
                                $next = $node->cloneNode();
                                $next->textContent = $part;
                                $node->parentNode->insertBefore($break, $previous->nextSibling);
                                $node->parentNode->insertBefore($next, $break->nextSibling);
                                $previous = $next;
                            }
                        }
                    }
                }
                $zip->addFromString($name, $document->saveXML());
            }
        } finally {
            $zip->close();
        }
    }

    private function textNodes(DOMXPath $xpath, DOMElement $paragraph): array
    {
        $nodes = [];
        foreach ($xpath->query('.//w:t', $paragraph) as $node) {
            $parent = $node->parentNode;
            while ($parent && ! ($parent->namespaceURI === self::Namespace && $parent->localName === 'p')) {
                $parent = $parent->parentNode;
            }
            if ($parent === $paragraph) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private function isTextPart(string $name): bool
    {
        return preg_match('~^word/(?:document|header\d*|footer\d*|footnotes|endnotes)\.xml$~', $name) === 1;
    }

    private function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->invalid('Nie można odczytać pliku DOCX.');
        }

        return $zip;
    }

    private function xml(string $xml): DOMDocument
    {
        if (trim($xml) === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            $this->invalid('DOCX zawiera niedozwolone odwołania XML.');
        }
        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;
        if (! @$document->loadXML($xml, LIBXML_NONET)) {
            $this->invalid('Niepoprawna struktura XML w DOCX.');
        }

        return $document;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}

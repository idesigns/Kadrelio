<?php

namespace App\Services;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class MondayWorkbook
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function rows(string $path): array
    {
        $archive = new ZipArchive;
        if ($archive->open($path) !== true) {
            throw new RuntimeException('Nie można odczytać pliku XLSX: '.basename($path));
        }
        try {
            $strings = [];
            if ($archive->locateName('xl/sharedStrings.xml') !== false) {
                foreach ($this->xml($archive, 'xl/sharedStrings.xml')->children(self::NS)->si as $item) {
                    $strings[] = $this->text($item);
                }
            }
            $workbook = $this->xml($archive, 'xl/workbook.xml');
            $sheet = $workbook->children(self::NS)->sheets->sheet[0];
            $relationId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
            $sheetPath = null;
            foreach ($this->xml($archive, 'xl/_rels/workbook.xml.rels')->children('http://schemas.openxmlformats.org/package/2006/relationships') as $relation) {
                if ((string) $relation->attributes()['Id'] === $relationId && (string) $relation->attributes()['TargetMode'] !== 'External') {
                    $sheetPath = ltrim((string) $relation->attributes()['Target'], '/');
                    $sheetPath = str_starts_with($sheetPath, 'xl/') ? $sheetPath : 'xl/'.$sheetPath;
                }
            }
            if (! $sheetPath || str_contains($sheetPath, '..')) {
                throw new RuntimeException('Nieprawidłowa struktura arkusza XLSX.');
            }
            $rows = [];
            foreach ($this->xml($archive, $sheetPath)->children(self::NS)->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $cell) {
                    $type = (string) $cell->attributes()['t'];
                    $raw = (string) $cell->v;
                    $value = match ($type) {
                        's' => $strings[(int) $raw] ?? throw new RuntimeException('Nieprawidłowy indeks tekstu XLSX.'),
                        'inlineStr' => $this->text($cell),
                        default => $raw,
                    };
                    if ($value !== '' || isset($cell->f)) {
                        $column = preg_replace('/\d+/', '', (string) $cell->attributes()['r']);
                        $cells[$column] = ['value' => $value, 'type' => $type ?: 'n', 'style' => (string) $cell->attributes()['s'], 'formula' => isset($cell->f) ? (string) $cell->f : null];
                    }
                }
                if ($cells !== []) {
                    $rows[] = ['row' => (int) $row->attributes()['r'], 'cells' => $cells];
                }
            }

            return ['rows' => $rows, 'date1904' => in_array((string) $workbook->children(self::NS)->workbookPr->attributes()['date1904'], ['1', 'true'], true)];
        } finally {
            $archive->close();
        }
    }

    public function board(string $path): array
    {
        $workbook = $this->rows($path);
        $rows = $workbook['rows'];
        $headers = [];
        $columns = [];
        $group = null;
        $groups = [];
        $items = [];
        foreach (array_slice($rows, 1) as $row) {
            $values = array_filter(array_map(fn (array $cell): string => $cell['value'], $row['cells']), fn (string $value): bool => $value !== '');
            if ($values === []) {
                continue;
            }
            if (in_array('Item ID (auto generated)', $values, true)) {
                $headers = $values;
                foreach ($headers as $column => $label) {
                    if (isset($columns[$column]) && $columns[$column] !== $label) {
                        throw new RuntimeException('Różne nagłówki tej samej kolumny: '.basename($path));
                    }
                    $columns[$column] = $label;
                }

                continue;
            }
            $idColumn = array_search('Item ID (auto generated)', $headers, true);
            $sourceId = $idColumn !== false ? ($values[$idColumn] ?? '') : '';
            if (preg_match('/^\d+(?:\.0)?$/', $sourceId)) {
                $sourceId = preg_replace('/\.0$/', '', $sourceId);
                if (isset($items[$sourceId])) {
                    throw new RuntimeException('Powtórzony Item ID w arkuszu: '.basename($path));
                }
                $items[$sourceId] = ['id' => $sourceId, 'row' => $row['row'], 'group' => $group, 'headers' => $headers, 'values' => $values, 'cells' => $row['cells']];
            } elseif (count($values) === 1 && isset($values['A'])) {
                $group = $values['A'];
                if (! in_array($group, $groups, true)) {
                    $groups[] = $group;
                }
            } else {
                throw new RuntimeException('Wiersz bez Item ID wymaga sprawdzenia: '.basename($path).' (wiersz '.$row['row'].').');
            }
        }

        return ['title' => $rows[0]['cells']['A']['value'] ?? pathinfo($path, PATHINFO_FILENAME), 'columns' => $columns, 'groups' => $groups, 'items' => $items, 'date1904' => $workbook['date1904']];
    }

    public function table(string $path): array
    {
        $rows = $this->rows($path)['rows'];
        $headers = array_map(fn (array $cell): string => $cell['value'], $rows[1]['cells'] ?? []);
        if ($headers === []) {
            throw new RuntimeException('Brak nagłówka: '.basename($path));
        }

        return ['headers' => $headers, 'rows' => array_map(fn (array $row): array => ['row' => $row['row'], 'cells' => $row['cells'], 'values' => array_map(fn (array $cell): string => $cell['value'], $row['cells'])], array_slice($rows, 2))];
    }

    private function xml(ZipArchive $archive, string $path): SimpleXMLElement
    {
        $stat = $archive->statName($path);
        if (! $stat || $stat['size'] > 100 * 1024 * 1024) {
            throw new RuntimeException('Brak części XLSX lub przekroczony limit rozmiaru.');
        }
        $contents = $archive->getFromName($path);
        if ($contents === false || stripos($contents, '<!DOCTYPE') !== false || stripos($contents, '<!ENTITY') !== false) {
            throw new RuntimeException('Niedozwolona struktura XML.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false) {
                throw new RuntimeException('Nieprawidłowy XML w XLSX.');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function text(SimpleXMLElement $node): string
    {
        $node->registerXPathNamespace('s', self::NS);

        return implode('', array_map(fn (SimpleXMLElement $text): string => (string) $text, $node->xpath('.//s:t')));
    }
}

<?php

namespace App\Services;

use App\Models\MondayImportBatch;
use FilesystemIterator;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class MondayAssetImport
{
    public function run(MondayImportBatch $batch, string $directory, bool $commit, ?string $mapFile = null): array
    {
        $root = realpath($directory);
        if (! $root || ! is_dir($root)) {
            throw new RuntimeException('Nie znaleziono katalogu assets.');
        }
        $knownIds = $batch->assets()->pluck('id', 'source_id')->all();
        $candidates = [];
        $unrecognized = 0;
        if ($mapFile) {
            $mapping = json_decode(file_get_contents($mapFile), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($mapping)) {
                throw new RuntimeException('Mapa assets musi być obiektem JSON: resource_id → ścieżka względna.');
            }
            foreach ($mapping as $sourceId => $relative) {
                $path = is_string($relative) ? realpath($root.'/'.$relative) : false;
                if (! isset($knownIds[$sourceId]) || ! $path || ! is_file($path) || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                    throw new RuntimeException('Mapa assets zawiera nieznany zasób lub ścieżkę poza katalogiem.');
                }
                $candidates[$sourceId][] = $path;
            }
        } else {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isLink()) {
                    throw new RuntimeException('Assets nie może zawierać dowiązań symbolicznych.');
                }
                if (! $file->isFile()) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                preg_match_all('/(?:^|[\/_.-])(\d{5,})(?=[\/_.-]|$)/', $relative, $matches);
                $ids = array_values(array_unique(array_filter($matches[1], fn (string $id): bool => isset($knownIds[$id]))));
                if (count($ids) === 1) {
                    $candidates[$ids[0]][] = $file->getPathname();
                } else {
                    $unrecognized++;
                }
            }
        }
        $report = ['mode' => $commit ? 'committed' : 'dry_run', 'matched' => 0, 'already_present' => 0, 'conflicts' => [], 'unrecognized_files' => $unrecognized, 'missing' => count($knownIds)];
        foreach ($candidates as $sourceId => $paths) {
            $hashes = [];
            foreach ($paths as $path) {
                $hashes[hash_file('sha256', $path)] = $path;
            }
            $asset = $batch->assets()->findOrFail($knownIds[$sourceId]);
            if (count($hashes) !== 1 || ($asset->checksum && ! isset($hashes[$asset->checksum]))) {
                $report['conflicts'][] = (string) $sourceId;

                continue;
            }
            $checksum = array_key_first($hashes);
            $source = $hashes[$checksum];
            if ($asset->path && Storage::disk('local')->exists($asset->path)) {
                $existingHash = hash_file('sha256', Storage::disk('local')->path($asset->path));
                if (! hash_equals($checksum, $existingHash)) {
                    $report['conflicts'][] = (string) $sourceId;

                    continue;
                }
                $report['already_present']++;

                continue;
            }
            if ($commit) {
                $destination = 'monday/'.$batch->source_key.'/assets/'.$sourceId.'/'.$checksum;
                $stream = fopen($source, 'rb');
                try {
                    if (! $stream || ! Storage::disk('local')->put($destination, $stream, ['visibility' => 'private'])) {
                        throw new RuntimeException('Nie udało się zapisać zasobu '.$sourceId.'.');
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                if (! hash_equals($checksum, hash_file('sha256', Storage::disk('local')->path($destination)))) {
                    throw new RuntimeException('Suma kontrolna zasobu '.$sourceId.' zmieniła się podczas kopiowania.');
                }
                $asset->path = $destination;
                $asset->checksum = $checksum;
                $asset->size = filesize($source);
                $asset->save();
            }
            $report['matched']++;
        }
        $report['missing'] = $commit ? $batch->assets()->whereNull('path')->count() : count($knownIds) - $report['matched'] - $report['already_present'];

        return $report;
    }
}

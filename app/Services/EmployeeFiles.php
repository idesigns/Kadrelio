<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmployeeFiles
{
    public function store(Employee $employee, array $uploads): void
    {
        if ($uploads === []) {
            return;
        }
        $personal = $employee->personal_data ?? [];
        $files = $personal['attachments'] ?? [];
        foreach ($uploads as $upload) {
            if (! $upload instanceof UploadedFile) {
                throw new \InvalidArgumentException('Oczekiwano pliku formularza.');
            }
            $id = (string) Str::uuid();
            $extension = match ($upload->getMimeType()) {
                'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
                default => throw new \InvalidArgumentException('Niedozwolony typ pliku formularza.'),
            };
            $path = $upload->storeAs('employees/'.$employee->company_id.'/'.$employee->id, $id.'.'.$extension, 'local');
            if (! $path) {
                throw new \RuntimeException('Nie można zapisać załącznika formularza.');
            }
            $files[$id] = ['id' => $id, 'kind' => 'student_card', 'name' => mb_substr(preg_replace('/[\r\n\x00]/', '', basename($upload->getClientOriginalName())), 0, 180), 'path' => $path, 'mime' => $upload->getMimeType(), 'size' => $upload->getSize(), 'checksum' => hash_file('sha256', $upload->getRealPath()), 'uploaded_at' => now()->toIso8601String()];
        }
        $employee->personal_data = array_merge($personal, ['attachments' => $files]);
        $employee->save();
    }

    public function find(Employee $employee, string $id): array
    {
        $file = $employee->personal_data['attachments'][$id] ?? null;
        $disk = Storage::disk('local');
        abort_unless(is_array($file) && $disk->exists($file['path']), 404);
        abort_unless(hash_equals($file['checksum'], hash('sha256', $disk->get($file['path']))), 404);

        return $file;
    }
}

<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * The passport scan copy: a JPG, PNG or PDF of at most 5 MB, whose real
 * content type (read with finfo, not the client's claim) matches its
 * extension. A file renamed to .jpg is rejected.
 */
class PassportScanFile implements ValidationRule
{
    /**
     * Real MIME type => the extensions that may carry it.
     *
     * @var array<string, list<string>>
     */
    public const TYPES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'application/pdf' => ['pdf'],
    ];

    /**
     * Largest scan in kilobytes.
     */
    public const MAX_KB = 5120;

    /**
     * The full rule list for a scan field.
     *
     * @return list<mixed>
     */
    public static function rules(bool $required): array
    {
        return [
            $required ? 'required' : 'sometimes',
            'file',
            'mimes:jpg,jpeg,png,pdf',
            'max:'.self::MAX_KB,
            new self,
        ];
    }

    /**
     * The real MIME type of the file, from its content.
     */
    public static function realMime(UploadedFile $file): ?string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        return is_string($mime) ? $mime : null;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The scan must be a file.');

            return;
        }

        $mime = self::realMime($value);
        $extension = strtolower($value->getClientOriginalExtension());

        if (! isset(self::TYPES[$mime]) || ! in_array($extension, self::TYPES[$mime], true)) {
            $fail('The scan must be a real JPG, PNG or PDF file.');
        }
    }
}

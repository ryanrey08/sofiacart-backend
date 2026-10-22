<?php

namespace App\Http\Requests\Admin;

use App\Services\LegalDocumentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A new Terms / Privacy Policy version (PDF or Word .docx). The extension, MIME type and actual
 * contents must all agree; see LegalDocumentService::detectFormat().
 */
class UploadLegalDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:pdf,docx', 'max:'.config('legal_documents.max_kilobytes')],
            'version_label' => ['nullable', 'string', 'max:50'],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.extensions' => 'The file must be a PDF or Word (.docx) document.',
            'file.max' => 'The file may not be larger than '.round(config('legal_documents.max_kilobytes') / 1024, 1).' MB.',
            'file.uploaded' => 'The file could not be uploaded. It may be larger than the allowed size.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('file');

                if ($validator->errors()->has('file') || ! $file) {
                    return;
                }

                if (app(LegalDocumentService::class)->detectFormat($file) === null) {
                    $validator->errors()->add('file', 'The file must be a valid PDF or Word (.docx) document. Macro-enabled or renamed files are not accepted.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['version_label', 'notes'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field)) ?: null]);
            }
        }
    }
}

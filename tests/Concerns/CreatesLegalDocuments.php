<?php

namespace Tests\Concerns;

use App\Enums\LegalDocumentAudience;
use App\Enums\LegalDocumentStatus;
use App\Enums\LegalDocumentType;
use App\Models\LegalDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Builds structurally valid PDF / Word files for upload tests and published legal documents for
 * registration tests. Call Storage::fake('legal') first.
 */
trait CreatesLegalDocuments
{
    protected function pdfUpload(string $name = 'terms.pdf', string $text = 'SofiaCart Terms'): UploadedFile
    {
        $body = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\n% {$text}\ntrailer << /Root 1 0 R >>\n%%EOF\n";

        return UploadedFile::fake()->createWithContent($name, $body);
    }

    protected function docxUpload(string $name = 'privacy.docx', string $text = 'SofiaCart Privacy', bool $macroEnabled = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $mainType = $macroEnabled
            ? 'application/vnd.ms-word.document.macroEnabled.main+xml'
            : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="'.$mainType.'"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'.htmlspecialchars($text).'</w:t></w:r></w:p></w:body></w:document>');
        if ($macroEnabled) {
            $zip->addFromString('word/vbaProject.bin', 'macro');
        }
        $zip->close();

        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
    }

    protected function publishedLegalDocument(
        LegalDocumentAudience $audience,
        LegalDocumentType $type,
        int $version = 1,
        LegalDocumentStatus $status = LegalDocumentStatus::Published,
    ): LegalDocument {
        $path = "{$audience->value}/{$type->value}/".Str::uuid()->toString().'.pdf';
        Storage::disk('legal')->put($path, "%PDF-1.4\n% {$audience->value} {$type->value} v{$version}\n%%EOF\n");

        return LegalDocument::create([
            'audience' => $audience,
            'type' => $type,
            'version' => $version,
            'status' => $status,
            'is_current' => $status === LegalDocumentStatus::Published ? true : null,
            'original_filename' => "{$type->value}.pdf",
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 42,
            'checksum' => str_repeat('a', 64),
            'uploaded_at' => now(),
            'published_at' => $status === LegalDocumentStatus::Published ? now() : null,
        ]);
    }

    /**
     * @return array{terms: LegalDocument, privacy: LegalDocument}
     */
    protected function publishLegalDocumentsFor(LegalDocumentAudience $audience): array
    {
        return [
            'terms' => $this->publishedLegalDocument($audience, LegalDocumentType::Terms),
            'privacy' => $this->publishedLegalDocument($audience, LegalDocumentType::Privacy),
        ];
    }
}

<?php

namespace Tests\Feature;

use App\Admin\AdminRoleRegistry;
use App\Enums\LegalDocumentAudience;
use App\Enums\LegalDocumentStatus;
use App\Enums\LegalDocumentType;
use App\Models\AdminAuditLog;
use App\Models\AdminRole;
use App\Models\LegalDocument;
use App\Models\User;
use Database\Seeders\AdminAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesLegalDocuments;
use Tests\TestCase;

class LegalDocumentManagementTest extends TestCase
{
    use CreatesLegalDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('legal');
        $this->seed(AdminAuthorizationSeeder::class);
    }

    public function test_super_admin_uploads_a_pdf_draft_that_is_previewable_but_not_public(): void
    {
        $admin = $this->actingAsAdmin(AdminRoleRegistry::SUPER_ADMIN);

        $response = $this->post('/api/admin/legal-documents/buyer/terms/versions', [
            'file' => $this->pdfUpload('Buyer Terms.pdf', 'Buyer terms v1'),
            'version_label' => '1.0',
            'effective_date' => '2026-06-18',
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.audience', 'buyer')
            ->assertJsonPath('data.type', 'terms')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.version_label', '1.0')
            ->assertJsonPath('data.effective_date', '2026-06-18')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.format', 'pdf')
            ->assertJsonPath('data.original_filename', 'Buyer Terms.pdf')
            ->assertJsonPath('data.uploaded_by.id', $admin->id)
            ->assertJsonMissingPath('data.file_path');
        $this->assertStringNotContainsString('buyer/terms/', $response->getContent());

        $document = LegalDocument::findOrFail($response->json('data.id'));
        Storage::disk('legal')->assertExists($document->file_path);
        $this->assertMatchesRegularExpression('#^buyer/terms/[0-9a-f-]{36}\.pdf$#', $document->file_path);
        $this->assertSame(hash('sha256', Storage::disk('legal')->get($document->file_path)), $document->checksum);

        // Admin preview streams the draft; the public endpoints do not expose it.
        $preview = $this->get("/api/admin/legal-documents/versions/{$document->id}/file");
        $preview->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('Buyer terms v1', $preview->streamedContent());

        $this->getJson('/api/legal-documents/buyer/terms')->assertNotFound();
        $this->get('/api/legal-documents/buyer/terms/file', ['Accept' => 'application/json'])->assertNotFound();
        $this->getJson('/api/legal-documents/buyer')->assertOk()->assertJsonPath('data.terms', null);

        $this->assertTrue(AdminAuditLog::query()
            ->where('action', 'legal_document.uploaded')
            ->where('subject_id', $document->id)
            ->where('actor_id', $admin->id)
            ->exists());
        // The generic mutation entry is not written in addition to the specific one.
        $this->assertSame(0, AdminAuditLog::query()->where('action', 'admin.legal-documents.store')->count());
    }

    public function test_word_documents_are_accepted(): void
    {
        $this->actingAsAdmin(AdminRoleRegistry::SUPER_ADMIN);

        $id = $this->post('/api/admin/legal-documents/merchant/privacy/versions', [
            'file' => $this->docxUpload('Merchant Data Privacy Act - SofiaCart.docx'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.format', 'docx')
            ->assertJsonPath('data.mime_type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->assertJsonPath('data.original_filename', 'Merchant Data Privacy Act - SofiaCart.docx')
            ->json('data.id');

        $this->get("/api/admin/legal-documents/versions/{$id}/file?download=1")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->assertHeader('Content-Disposition', 'attachment; filename="Merchant Data Privacy Act - SofiaCart.docx"');
    }

    public function test_publishing_makes_the_version_public_and_replacement_supersedes_it(): void
    {
        $admin = $this->actingAsAdmin(AdminRoleRegistry::SUPER_ADMIN);

        $first = $this->upload('buyer', 'privacy', $this->pdfUpload('privacy-v1.pdf', 'Privacy version one'));
        $this->postJson("/api/admin/legal-documents/versions/{$first}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.published_by.id', $admin->id);

        $this->getJson('/api/legal-documents/buyer/privacy')
            ->assertOk()
            ->assertJsonPath('data.id', $first)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.title', 'Data Privacy Policy')
            ->assertJsonPath('data.format', 'pdf')
            ->assertJsonMissingPath('data.file_path')
            ->assertJsonMissingPath('data.original_filename');

        $public = $this->get('/api/legal-documents/buyer/privacy/file');
        $public->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline;', (string) $public->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Privacy version one', $public->streamedContent());
        $this->assertStringContainsString(
            'attachment; filename=SofiaCart-Buyer-Data-Privacy-Policy-v1.pdf',
            (string) $this->get('/api/legal-documents/buyer/privacy/file?download=1')->headers->get('Content-Disposition'),
        );

        // A replacement stays a draft (v1 remains public) until it is published.
        $second = $this->upload('buyer', 'privacy', $this->pdfUpload('privacy-v2.pdf', 'Privacy version two'));
        $this->getJson('/api/legal-documents/buyer/privacy')->assertJsonPath('data.id', $first);

        $this->postJson("/api/admin/legal-documents/versions/{$second}/publish")->assertOk()->assertJsonPath('data.version', 2);

        $this->getJson('/api/legal-documents/buyer/privacy')->assertJsonPath('data.id', $second)->assertJsonPath('data.version', 2);
        $this->assertStringContainsString('Privacy version two', $this->get('/api/legal-documents/buyer/privacy/file')->streamedContent());
        $this->assertSame(LegalDocumentStatus::Superseded, LegalDocument::findOrFail($first)->status);
        $this->assertSame(1, LegalDocument::query()->where('audience', 'buyer')->where('type', 'privacy')->where('is_current', true)->count());

        // Old versions are kept and visible in the admin history.
        $this->getJson('/api/admin/legal-documents/buyer/privacy/versions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.version', 2)
            ->assertJsonPath('data.0.status', 'published')
            ->assertJsonPath('data.1.status', 'superseded');

        $this->getJson('/api/admin/legal-documents')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonFragment(['audience' => 'buyer', 'type' => 'privacy', 'versions_count' => 2]);

        $this->assertTrue(AdminAuditLog::query()->where('action', 'legal_document.published')->where('subject_id', $second)->exists());
        $this->assertTrue(AdminAuditLog::query()->where('action', 'legal_document.replaced')->where('subject_id', $first)->exists());

        // Other slots are unaffected.
        $this->getJson('/api/legal-documents/buyer/terms')->assertNotFound();
        $this->getJson('/api/legal-documents/merchant/privacy')->assertNotFound();
    }

    public function test_unpublishing_removes_public_access_and_is_audited(): void
    {
        $this->actingAsAdmin(AdminRoleRegistry::SUPER_ADMIN);
        $id = $this->upload('merchant', 'terms', $this->pdfUpload());
        $this->postJson("/api/admin/legal-documents/versions/{$id}/publish")->assertOk();
        $this->getJson('/api/legal-documents/merchant/terms')->assertOk();

        $this->postJson("/api/admin/legal-documents/versions/{$id}/unpublish")
            ->assertOk()
            ->assertJsonPath('data.status', 'unpublished');

        $this->getJson('/api/legal-documents/merchant/terms')->assertNotFound()->assertJsonPath('message', 'This document is temporarily unavailable.');
        $this->get('/api/legal-documents/merchant/terms/file', ['Accept' => 'application/json'])->assertNotFound();
        $this->getJson('/api/legal-documents/merchant')->assertOk()->assertJsonPath('data.terms', null);

        // Admins can still preview it, and it can be published again.
        $this->get("/api/admin/legal-documents/versions/{$id}/file")->assertOk();
        $this->assertTrue(AdminAuditLog::query()->where('action', 'legal_document.unpublished')->where('subject_id', $id)->exists());

        $this->postJson("/api/admin/legal-documents/versions/{$id}/unpublish")->assertStatus(409);
        $this->postJson("/api/admin/legal-documents/versions/{$id}/publish")->assertOk();
        $this->postJson("/api/admin/legal-documents/versions/{$id}/publish")->assertStatus(409);
    }

    public function test_only_admins_with_legal_permissions_can_manage_documents(): void
    {
        $draft = $this->publishedLegalDocument(LegalDocumentAudience::Buyer, LegalDocumentType::Terms, status: LegalDocumentStatus::Draft);

        // Guests.
        $this->getJson('/api/admin/legal-documents')->assertUnauthorized();
        $this->postJson('/api/admin/legal-documents/buyer/terms/versions', [])->assertUnauthorized();

        // Merchants.
        Sanctum::actingAs(User::factory()->create(), ['merchant']);
        $this->getJson('/api/admin/legal-documents')->assertForbidden();
        $this->post('/api/admin/legal-documents/buyer/terms/versions', ['file' => $this->pdfUpload()], ['Accept' => 'application/json'])->assertForbidden();

        // Admin role: view only.
        $this->actingAsAdmin(AdminRoleRegistry::ADMIN);
        $this->getJson('/api/admin/legal-documents')->assertOk();
        $this->get("/api/admin/legal-documents/versions/{$draft->id}/file")->assertOk();
        $this->post('/api/admin/legal-documents/buyer/terms/versions', ['file' => $this->pdfUpload()], ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson("/api/admin/legal-documents/versions/{$draft->id}/publish")->assertForbidden();

        // Roles without legal permissions.
        $this->actingAsAdmin(AdminRoleRegistry::CUSTOMER_SUPPORT);
        $this->getJson('/api/admin/legal-documents')->assertForbidden();
        $this->get("/api/admin/legal-documents/versions/{$draft->id}/file", ['Accept' => 'application/json'])->assertForbidden();

        $this->assertSame(LegalDocumentStatus::Draft, $draft->fresh()->status);
        $this->assertSame(1, LegalDocument::count());
    }

    public function test_invalid_and_oversized_files_are_rejected(): void
    {
        $this->actingAsAdmin(AdminRoleRegistry::SUPER_ADMIN);
        $upload = fn (UploadedFile $file) => $this->post('/api/admin/legal-documents/buyer/terms/versions', ['file' => $file], ['Accept' => 'application/json']);

        $upload(UploadedFile::fake()->createWithContent('terms.txt', 'plain text'))->assertUnprocessable()->assertInvalid('file');
        $upload(UploadedFile::fake()->image('terms.png'))->assertUnprocessable()->assertInvalid('file');
        // Wrong contents behind an allowed extension.
        $upload(UploadedFile::fake()->createWithContent('terms.pdf', "#!/bin/sh\necho owned\n"))->assertUnprocessable()->assertInvalid('file');
        $upload(UploadedFile::fake()->createWithContent('terms.docx', 'PK not really a zip'))->assertUnprocessable()->assertInvalid('file');
        // A PDF renamed to .docx, and a macro-enabled Word file renamed to .docx.
        $upload($this->pdfUpload('terms.docx'))->assertUnprocessable()->assertInvalid('file');
        $upload($this->docxUpload('terms.docx', macroEnabled: true))->assertUnprocessable()->assertInvalid('file');
        // Over the configured limit.
        config(['legal_documents.max_kilobytes' => 1]);
        $upload($this->pdfUpload('terms.pdf', str_repeat('x', 2048)))->assertUnprocessable()->assertInvalid(['file' => 'may not be larger than']);
        $this->post('/api/admin/legal-documents/buyer/terms/versions', [], ['Accept' => 'application/json'])->assertUnprocessable()->assertInvalid('file');

        $this->assertSame(0, LegalDocument::count());
        $this->assertSame([], Storage::disk('legal')->allFiles());
    }

    public function test_unknown_slots_and_versions_are_not_routable(): void
    {
        $this->actingAsAdmin(AdminRoleRegistry::SUPER_ADMIN);

        $this->post('/api/admin/legal-documents/vendor/terms/versions', ['file' => $this->pdfUpload()], ['Accept' => 'application/json'])->assertNotFound();
        $this->getJson('/api/legal-documents/buyer/cookies')->assertNotFound();
        $this->getJson('/api/legal-documents/..%2F..%2F.env/terms')->assertNotFound();
        $this->get('/api/admin/legal-documents/versions/999/file', ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_a_tampered_storage_path_is_never_streamed(): void
    {
        $this->actingAsAdmin(AdminRoleRegistry::SUPER_ADMIN);
        $document = $this->publishedLegalDocument(LegalDocumentAudience::Buyer, LegalDocumentType::Terms);
        Storage::disk('legal')->put('secret.txt', 'secret');
        $document->forceFill(['file_path' => '../secret.txt'])->save();

        $this->get("/api/admin/legal-documents/versions/{$document->id}/file", ['Accept' => 'application/json'])->assertNotFound();
        $this->get('/api/legal-documents/buyer/terms/file', ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_import_command_uploads_and_publishes_a_real_file_unchanged(): void
    {
        $admin = $this->createAdmin(AdminRoleRegistry::SUPER_ADMIN);
        $source = $this->docxUpload('Buyer Terms and Condition - SofaCart.docx', 'BUYER TERMS AND CONDITIONS');
        $path = sys_get_temp_dir().'/legal-import-'.uniqid().'.docx';
        copy($source->getRealPath(), $path);

        $this->artisan('legal-documents:import', [
            'audience' => 'buyer',
            'type' => 'terms',
            'path' => $path,
            '--admin' => $admin->email,
            '--label' => '1.0',
            '--effective-date' => '2026-06-18',
            '--publish' => true,
        ])->assertSuccessful();

        $document = LegalDocument::query()->sole();
        $this->assertTrue($document->isPublished());
        $this->assertSame(hash_file('sha256', $path), $document->checksum);
        $this->assertSame(file_get_contents($path), Storage::disk('legal')->get($document->file_path));
        $this->getJson('/api/legal-documents/buyer/terms')->assertOk()->assertJsonPath('data.format', 'docx')->assertJsonPath('data.effective_date', '2026-06-18');

        $viewer = $this->createAdmin(AdminRoleRegistry::ADMIN);
        $this->artisan('legal-documents:import', ['audience' => 'buyer', 'type' => 'terms', 'path' => $path, '--admin' => $viewer->email])->assertFailed();
        $this->assertSame(1, LegalDocument::count());

        @unlink($path);
    }

    protected function upload(string $audience, string $type, UploadedFile $file): int
    {
        return $this->post("/api/admin/legal-documents/{$audience}/{$type}/versions", ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');
    }

    protected function actingAsAdmin(string $role): User
    {
        $admin = $this->createAdmin($role);
        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        return $admin;
    }

    protected function createAdmin(string $role): User
    {
        $admin = User::factory()->admin()->create(['email' => fake()->unique()->safeEmail()]);
        $admin->adminRoles()->sync([AdminRole::query()->where('slug', $role)->firstOrFail()->id]);

        return $admin->fresh(['adminRoles.permissions', 'adminPermissions']);
    }
}

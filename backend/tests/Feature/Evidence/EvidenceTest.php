<?php

namespace Tests\Feature\Evidence;

use App\Enums\EvidenceCategory;
use App\Enums\InspectionItem;
use App\Exceptions\BusinessRuleException;
use App\Models\Bus;
use App\Models\EvidenceFile;
use App\Models\Route;
use App\Models\Trip;
use App\Models\User;
use App\Services\Evidence\EvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BR-367 — evidence uploads.
 *
 * The contract this replaces accepted `photo_path` as a string. No upload ever
 * happened, nothing checked a MIME type, and the safety rules that demand a
 * photograph — for a failed brake check, for every operational incident — were
 * satisfied by typing a filename.
 */
class EvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('evidence');
    }

    private function upload(User $actor, UploadedFile $file, EvidenceCategory $category)
    {
        return $this->postJson('/api/v1/evidence', [
            'category' => $category->value,
            'file' => $file,
        ], $this->authHeader($actor));
    }

    // ====================================================================
    // WHAT IS ACCEPTED
    // ====================================================================

    #[Test]
    public function a_driver_can_upload_a_photograph(): void
    {
        $driver = $this->createDriver();

        $response = $this->upload(
            $driver,
            UploadedFile::fake()->image('damage.jpg', 1200, 900),
            EvidenceCategory::INCIDENT_PHOTO,
        )->assertStatus(201);

        $this->assertDatabaseCount('evidence_files', 1);
        $this->assertNotNull($response->json('data.checksum'));
    }

    #[Test]
    public function the_file_lands_on_the_private_disk(): void
    {
        $driver = $this->createDriver();

        $this->upload($driver, UploadedFile::fake()->image('damage.jpg'),
            EvidenceCategory::INCIDENT_PHOTO)->assertStatus(201);

        $evidence = EvidenceFile::first();

        Storage::disk('evidence')->assertExists($evidence->path);
        // Never the public disk, and never symlinked into public/.
        $this->assertSame('evidence', $evidence->disk);
    }

    #[Test]
    public function the_stored_name_is_generated_not_the_clients(): void
    {
        $driver = $this->createDriver();

        $this->upload(
            $driver,
            UploadedFile::fake()->image('../../../../etc/passwd.jpg'),
            EvidenceCategory::INCIDENT_PHOTO,
        )->assertStatus(201);

        $evidence = EvidenceFile::first();

        // A client-supplied name reaches the filesystem as a path, and
        // "../../.env" is a filename.
        $this->assertStringNotContainsString('..', $evidence->path);
        $this->assertStringNotContainsString('passwd', $evidence->path);
        $this->assertStringStartsWith('incident_photo/', $evidence->path);
    }

    #[Test]
    public function a_pdf_is_refused_as_a_photograph(): void
    {
        $driver = $this->createDriver();

        $this->upload(
            $driver,
            UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
            EvidenceCategory::INCIDENT_PHOTO,
        )->assertStatus(409);

        $this->assertDatabaseCount('evidence_files', 0);
    }

    #[Test]
    public function a_pdf_is_accepted_as_a_document(): void
    {
        $admin = $this->createAdmin();

        $this->upload(
            $admin,
            UploadedFile::fake()->create('insurance.pdf', 100, 'application/pdf'),
            EvidenceCategory::VEHICLE_CERTIFICATE,
        )->assertStatus(201);
    }

    #[Test]
    public function an_executable_renamed_as_an_image_is_refused(): void
    {
        $driver = $this->createDriver();

        // A real temp file, not UploadedFile::fake(): the fake reports a MIME
        // type derived from the extension, so it cannot exercise the check
        // that matters. This writes actual executable bytes under a .jpg name,
        // which is what the attack looks like.
        $path = tempnam(sys_get_temp_dir(), 'ctms').'.jpg';
        file_put_contents($path, "MZ\x90\x00\x03".str_repeat("\x00", 200));

        $file = new UploadedFile($path, 'payload.jpg', null, null, true);

        $this->expectException(BusinessRuleException::class);

        try {
            app(EvidenceService::class)->store($file, EvidenceCategory::INCIDENT_PHOTO, $driver);
        } finally {
            $this->assertDatabaseCount('evidence_files', 0);
            @unlink($path);
        }
    }

    #[Test]
    public function an_oversized_file_is_refused(): void
    {
        $driver = $this->createDriver();

        config(['ctms.evidence.max_photo_kb' => 100]);

        $this->upload(
            $driver,
            UploadedFile::fake()->image('huge.jpg')->size(5000),
            EvidenceCategory::INCIDENT_PHOTO,
        )->assertStatus(409);
    }

    #[Test]
    public function an_upload_with_no_file_is_refused(): void
    {
        $driver = $this->createDriver();

        $this->postJson('/api/v1/evidence', [
            'category' => EvidenceCategory::INCIDENT_PHOTO->value,
        ], $this->authHeader($driver))->assertStatus(422);
    }

    #[Test]
    public function a_student_cannot_upload(): void
    {
        $student = $this->createStudent();

        $this->upload($student, UploadedFile::fake()->image('x.jpg'),
            EvidenceCategory::INCIDENT_PHOTO)->assertStatus(403);
    }

    #[Test]
    public function uploading_requires_authentication(): void
    {
        $this->postJson('/api/v1/evidence', [])->assertStatus(401);
    }

    // ====================================================================
    // WHAT COMES BACK — BR-367
    // ====================================================================

    #[Test]
    public function the_upload_response_carries_no_path_and_no_url(): void
    {
        $driver = $this->createDriver();

        $body = $this->upload($driver, UploadedFile::fake()->image('x.jpg'),
            EvidenceCategory::INCIDENT_PHOTO)->assertStatus(201)->getContent();

        $evidence = EvidenceFile::first();

        // A URL gets pasted into a chat message and then works for whoever
        // receives it.
        $this->assertStringNotContainsString($evidence->path, $body);
        $this->assertStringNotContainsString('"disk"', $body);
        $this->assertStringNotContainsString('http', $body);
    }

    #[Test]
    public function the_uploader_can_download_their_own_file(): void
    {
        $driver = $this->createDriver();

        $id = $this->upload($driver, UploadedFile::fake()->image('x.jpg'),
            EvidenceCategory::INCIDENT_PHOTO)->json('data.id');

        $this->getJson("/api/v1/evidence/{$id}", $this->authHeader($driver))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$id.'.jpg"');
    }

    #[Test]
    public function a_download_is_never_rendered_inline(): void
    {
        $driver = $this->createDriver();

        $id = $this->upload($driver, UploadedFile::fake()->image('x.jpg'),
            EvidenceCategory::INCIDENT_PHOTO)->json('data.id');

        $response = $this->getJson("/api/v1/evidence/{$id}", $this->authHeader($driver))->assertOk();

        // An image rendered in the browser from a private store is one
        // redirect away from being embedded somewhere it should not be.
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    #[Test]
    public function another_driver_cannot_download_it(): void
    {
        $driver = $this->createDriver();
        $other = $this->createDriver();

        $id = $this->upload($driver, UploadedFile::fake()->image('x.jpg'),
            EvidenceCategory::INCIDENT_PHOTO)->json('data.id');

        // Holding the id is not authority: the id appears in an incident
        // response, which more people can read than should open the picture.
        $this->getJson("/api/v1/evidence/{$id}", $this->authHeader($other))
            ->assertStatus(403);
    }

    #[Test]
    public function a_student_cannot_download_evidence(): void
    {
        $driver = $this->createDriver();
        $student = $this->createStudent();

        $id = $this->upload($driver, UploadedFile::fake()->image('x.jpg'),
            EvidenceCategory::INCIDENT_PHOTO)->json('data.id');

        $this->getJson("/api/v1/evidence/{$id}", $this->authHeader($student))
            ->assertStatus(403);
    }

    #[Test]
    public function operations_can_download_any_evidence(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();

        $id = $this->upload($driver, UploadedFile::fake()->image('x.jpg'),
            EvidenceCategory::INCIDENT_PHOTO)->json('data.id');

        $this->getJson("/api/v1/evidence/{$id}", $this->authHeader($admin))->assertOk();
    }

    #[Test]
    public function downloading_requires_authentication(): void
    {
        $driver = $this->createDriver();

        $id = $this->upload($driver, UploadedFile::fake()->image('x.jpg'),
            EvidenceCategory::INCIDENT_PHOTO)->json('data.id');

        $this->getJson("/api/v1/evidence/{$id}")->assertStatus(401);
    }

    // ====================================================================
    // CLAIMING — ONE PHOTOGRAPH, ONE REPORT
    // ====================================================================

    /**
     * @return array{0: User, 1: Trip}
     */
    private function runningTrip(): array
    {
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->withCapacity(40)->create();

        $items = array_map(fn (InspectionItem $i) => ['item' => $i->value, 'passed' => true], InspectionItem::cases());
        $this->postJson("/api/v1/buses/{$bus->id}/inspections", [
            'items' => $items, 'odometer_reading' => 10000,
        ], $this->authHeader($driverUser))->assertStatus(201);

        $trip = Trip::factory()->departingNow()->create([
            'bus_id' => $bus->id,
            'driver_id' => $driverUser->driver->id,
            'route_id' => Route::factory()->withStops()->create()->id,
        ]);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driverUser))->assertOk();

        return [$driverUser, $trip->fresh()];
    }

    #[Test]
    public function citing_a_photograph_attaches_it_to_the_report(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $id = $this->incidentEvidence($driver);

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'BREAKDOWN',
            'trip_id' => $trip->id,
            'description' => 'Engine has failed.',
            'evidence_id' => $id,
        ], $this->authHeader($driver))->assertStatus(201);

        $evidence = EvidenceFile::find($id);

        $this->assertTrue($evidence->isAttached());
        $this->assertNotNull($evidence->attached_at);
    }

    #[Test]
    public function one_photograph_cannot_close_two_incidents(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $id = $this->incidentEvidence($driver);

        $payload = [
            'incident_type' => 'BREAKDOWN',
            'trip_id' => $trip->id,
            'description' => 'Engine has failed.',
            'evidence_id' => $id,
        ];

        $this->postJson('/api/v1/incidents', $payload, $this->authHeader($driver))->assertStatus(201);

        // Otherwise a single picture of a cracked windscreen evidences every
        // breakdown a driver reports for the rest of the term.
        $this->postJson('/api/v1/incidents', $payload + ['idempotency_key' => 'second'],
            $this->authHeader($driver))->assertStatus(409);
    }

    #[Test]
    public function a_driver_cannot_cite_another_drivers_photograph(): void
    {
        [$driver, $trip] = $this->runningTrip();
        $other = $this->createDriver();

        $id = $this->incidentEvidence($other);

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'BREAKDOWN',
            'trip_id' => $trip->id,
            'description' => 'Engine has failed.',
            'evidence_id' => $id,
        ], $this->authHeader($driver))->assertStatus(409);
    }

    #[Test]
    public function citing_a_file_that_does_not_exist_is_refused(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'BREAKDOWN',
            'trip_id' => $trip->id,
            'description' => 'Engine has failed.',
            'evidence_id' => '019fd800-0000-7000-8000-000000000000',
        ], $this->authHeader($driver))->assertStatus(409);
    }

    #[Test]
    public function an_operational_incident_still_demands_evidence(): void
    {
        [$driver, $trip] = $this->runningTrip();

        // The rule that used to be satisfied by typing a filename.
        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'BREAKDOWN',
            'trip_id' => $trip->id,
            'description' => 'Engine has failed.',
        ], $this->authHeader($driver))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['evidence_id']]);
    }

    #[Test]
    public function an_sos_never_demands_evidence(): void
    {
        [$driver, $trip] = $this->runningTrip();

        // Demanding a photograph from somebody in an emergency is
        // indefensible, and the upload subsystem does not change that.
        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS',
            'trip_id' => $trip->id,
        ], $this->authHeader($driver))->assertStatus(201);
    }

    // ====================================================================
    // HOUSEKEEPING
    // ====================================================================

    #[Test]
    public function an_abandoned_upload_is_swept(): void
    {
        $driver = $this->createDriver();

        $this->upload($driver, UploadedFile::fake()->image('x.jpg'),
            EvidenceCategory::INCIDENT_PHOTO)->assertStatus(201);

        $this->travel(3)->days();

        $purged = app(EvidenceService::class)->purgeOrphans();

        $this->assertSame(1, $purged);
        $this->assertDatabaseCount('evidence_files', 0);
    }

    #[Test]
    public function attached_evidence_is_never_swept(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $id = $this->incidentEvidence($driver);

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'BREAKDOWN',
            'trip_id' => $trip->id,
            'description' => 'Engine has failed.',
            'evidence_id' => $id,
        ], $this->authHeader($driver))->assertStatus(201);

        $this->travel(30)->days();

        app(EvidenceService::class)->purgeOrphans();

        // It is evidence now. The sweep only collects what a driver started
        // and abandoned.
        $this->assertNotNull(EvidenceFile::find($id));
    }

    #[Test]
    public function an_upload_is_audited(): void
    {
        $driver = $this->createDriver();

        $this->upload($driver, UploadedFile::fake()->image('x.jpg'),
            EvidenceCategory::INCIDENT_PHOTO)->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', ['action' => 'EVIDENCE_UPLOADED']);
    }

    #[Test]
    public function the_category_catalogue_is_served_to_the_client(): void
    {
        $driver = $this->createDriver();

        $response = $this->getJson('/api/v1/evidence/categories', $this->authHeader($driver))
            ->assertOk();

        // So a driver on a bad connection is told the limits before the
        // upload, not after it fails.
        $this->assertCount(count(EvidenceCategory::cases()), $response->json('data'));
        $this->assertArrayHasKey('max_kilobytes', $response->json('data.0'));
        $this->assertArrayHasKey('allowed_mime_types', $response->json('data.0'));
    }
}

<?php

namespace Tests;

use App\Enums\AccessLevel;
use App\Enums\EvidenceCategory;
use App\Enums\UserRole;
use App\Models\Admin;
use App\Models\Driver;
use App\Models\Student;
use App\Models\User;
use App\Services\Auth\TokenService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /**
     * Build an admin user together with its admin profile row.
     */
    protected function createAdmin(array $userAttributes = [], array $profileAttributes = []): User
    {
        $user = User::factory()->admin()->create($userAttributes);
        Admin::factory()->create(array_merge(['user_id' => $user->id], $profileAttributes));

        return $user->fresh();
    }

    /**
     * Build a driver user together with its driver profile row.
     */
    protected function createDriver(array $userAttributes = [], array $profileAttributes = []): User
    {
        $user = User::factory()->driver()->create($userAttributes);
        Driver::factory()->create(array_merge(['user_id' => $user->id], $profileAttributes));

        return $user->fresh();
    }

    /**
     * Build a student user together with its student profile row.
     */
    protected function createStudent(array $userAttributes = [], array $profileAttributes = []): User
    {
        $user = User::factory()->student()->create($userAttributes);
        Student::factory()->create(array_merge(['user_id' => $user->id], $profileAttributes));

        return $user->fresh();
    }

    /**
     * Mint a real access token for the user.
     *
     * Tests authenticate the way clients do — through the JWT middleware —
     * rather than via `actingAs()`, so the middleware itself stays covered.
     */
    protected function tokenFor(User $user): string
    {
        return app(TokenService::class)->issueAccessToken($user)['token'];
    }

    /**
     * Authorization header for the given user.
     *
     * @return array<string, string>
     */
    protected function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$this->tokenFor($user)];
    }

    /**
     * A user of the given role, with its profile row.
     */
    protected function userWithRole(UserRole $role): User
    {
        return match ($role) {
            UserRole::ADMIN => $this->createAdmin(),
            UserRole::DRIVER => $this->createDriver(),
            UserRole::STUDENT => $this->createStudent(),
        };
    }

    /**
     * An administrator at a named tier.
     *
     * `createAdmin()` produces an OPERATIONS admin, which is the everyday
     * case. Governance routes need SUPER_ADMIN; a supervisor is SUPPORT.
     */
    protected function createAdminAt(AccessLevel $level): User
    {
        return $this->createAdmin([], ['access_level' => $level->value]);
    }

    protected function createSuperAdmin(): User
    {
        return $this->createAdminAt(AccessLevel::SUPER_ADMIN);
    }

    protected function createSupervisor(): User
    {
        return $this->createAdminAt(AccessLevel::SUPPORT);
    }

    protected function createViewer(): User
    {
        return $this->createAdminAt(AccessLevel::VIEWER);
    }

    /**
     * Upload a real file and return its id (BR-367).
     *
     * The old contract let a test satisfy a safety rule by inventing a
     * filename — `'photo_path' => 'incidents/gearbox.jpg'` pointed at nothing
     * and passed. These helpers go through the upload endpoint, so a test that
     * claims a photograph was taken is a test where one exists.
     */
    protected function uploadEvidence(User $actor, EvidenceCategory $category, string $name = 'evidence.jpg'): string
    {
        Storage::fake('evidence');

        return $this->postJson('/api/v1/evidence', [
            'category' => $category->value,
            'file' => UploadedFile::fake()->image($name, 800, 600),
        ], $this->authHeader($actor))->assertStatus(201)->json('data.id');
    }

    protected function incidentEvidence(User $actor): string
    {
        return $this->uploadEvidence($actor, EvidenceCategory::INCIDENT_PHOTO);
    }

    protected function inspectionEvidence(User $actor): string
    {
        return $this->uploadEvidence($actor, EvidenceCategory::INSPECTION_PHOTO);
    }
}

<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * BR-512 — the system acts as an attributable actor in the audit trail.
 *
 * Background jobs write audit rows too, and until now those rows carried a
 * null actor. "Somebody cancelled this trip" is exactly the answer an audit
 * trail exists to prevent, so the scheduler gets a real identity like anyone
 * else.
 *
 * The row is deliberately unusable as a login: `is_active` is false, so it is
 * rejected by the authentication middleware on every request, and the password
 * is a random value nobody holds.
 */
return new class extends Migration
{
    public const SYSTEM_EMAIL = 'system@ctms.internal';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('is_active');

            $table->index('is_system');
        });

        DB::table('users')->insert([
            'id' => (string) Str::uuid7(),
            'email' => self::SYSTEM_EMAIL,
            // Never used: is_active = false blocks authentication outright.
            'password' => Hash::make(Str::random(64)),
            'first_name' => 'CTMS',
            'last_name' => 'System',
            'role' => UserRole::ADMIN->value,
            'is_active' => false,
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('users')->where('email', self::SYSTEM_EMAIL)->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_system']);
            $table->dropColumn('is_system');
        });
    }
};

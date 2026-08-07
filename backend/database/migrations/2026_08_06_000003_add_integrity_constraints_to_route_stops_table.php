<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two stops on the same route cannot share a position in the sequence.
 *
 * Without this, a concurrent "add stop" pair both reading "next = 4" would
 * produce a route with two fourth stops and an ambiguous running order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->unique(['route_id', 'sequence_number'], 'route_stops_route_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->dropUnique('route_stops_route_sequence_unique');
        });
    }
};

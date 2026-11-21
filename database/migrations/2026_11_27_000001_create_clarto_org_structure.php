<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Procesos y conocimiento» (docs/clarto/procesos.md): areas, positions (jobs, not Spatie roles) and the assignment of
 * a person to a position, with optional office and dates. An assignment is active while starts_on <= today (or null)
 * and ends_on >= today (or null): an end date in the past removes the access at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_areas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->string('code', 3);
            $table->string('name', 120);
            $table->string('business_line', 20)->default('transversal'); // inmobiliaria | alquileres | consultoria | gestoria | transversal
            $table->text('description')->nullable();
            $table->foreignId('manager_user_id')->nullable();
            $table->boolean('proposal')->default(false);
            $table->smallInteger('sort')->default(100);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['team_id', 'code']);
        });

        Schema::create('clarto_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->foreignId('area_id')->constrained('clarto_areas');
            $table->string('key', 40);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('proposal')->default(false);
            $table->smallInteger('sort')->default(100);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['team_id', 'key']);
        });

        Schema::create('clarto_position_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->foreignId('position_id')->constrained('clarto_positions')->cascadeOnDelete();
            $table->foreignId('user_id')->index();
            $table->foreignId('branch_id')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarto_position_user');
        Schema::dropIfExists('clarto_positions');
        Schema::dropIfExists('clarto_areas');
    }
};

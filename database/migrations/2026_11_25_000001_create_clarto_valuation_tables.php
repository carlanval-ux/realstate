<?php

declare(strict_types=1);

use App\Valuations\ValuationAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Valoraciones automáticas: requests (one per request_id), every calculation run (history), provider settings, permissions. */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_valuation_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->string('reference', 20)->nullable()->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->string('status', 20)->default('recibida');   // recibida|estimada|revision|error
            $table->string('operation', 20)->default('venta');
            $table->json('property');      // inmueble as sent by the web
            $table->json('contact');
            $table->json('consent')->nullable();
            $table->json('attribution')->nullable();
            $table->json('catastro')->nullable();   // detail of the confirmed reference (or why it is missing)
            $table->string('client_ip', 64)->nullable();
            $table->unsignedBigInteger('current_run_id')->nullable();
            $table->string('ghl_contact_id', 64)->nullable();
            $table->string('ghl_opportunity_id', 64)->nullable()->index();
            $table->string('ghl_status', 20)->default('pendiente');   // pendiente|sincronizada|error|sin_configurar
            $table->text('ghl_error')->nullable();
            $table->unsignedInteger('ghl_attempts')->default(0);
            $table->timestamp('ghl_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('property_id')->nullable();   // draft property opened when the opportunity is won
            $table->timestamps();
        });

        Schema::create('clarto_valuation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('valuation_request_id')->constrained('clarto_valuation_requests')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('version', 20);
            $table->string('status', 20);    // estimada|revision|error
            $table->string('provider', 20)->nullable();   // clarto|idealista|tinsa
            $table->boolean('simulated')->default(false);
            $table->json('result')->nullable();
            $table->json('provider_errors')->nullable();
            $table->text('message')->nullable();
            $table->text('summary')->nullable();
            $table->string('summary_source', 20)->nullable();   // ia|plantilla
            $table->string('pdf_path')->nullable();
            $table->unsignedTinyInteger('pdf_pages')->nullable();
            $table->string('trigger', 20)->default('api');   // api|reintento|recalculo|prueba
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->unique(['valuation_request_id', 'number']);
        });

        Schema::create('clarto_valuation_settings', function (Blueprint $table): void {
            $table->id();
            foreach (['idealista', 'tinsa'] as $p) {
                $table->string("{$p}_driver", 10)->nullable();
                $table->string("{$p}_url")->nullable();
                $table->string("{$p}_token_url")->nullable();
                $table->text("{$p}_client_id")->nullable();
                $table->text("{$p}_secret")->nullable();   // encrypted
            }
            $table->timestamps();
        });

        $permissions = config('permission.table_names.permissions', 'permissions');
        foreach (ValuationAccess::PERMISSIONS as $name) {
            if (! DB::table($permissions)->where('name', $name)->where('guard_name', 'web')->exists()) {
                DB::table($permissions)->insert(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        rescue(fn () => app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions(), report: false);
    }

    public function down(): void
    {
        DB::table(config('permission.table_names.permissions', 'permissions'))->whereIn('name', ValuationAccess::PERMISSIONS)->delete();
        Schema::dropIfExists('clarto_valuation_settings');
        Schema::dropIfExists('clarto_valuation_runs');
        Schema::dropIfExists('clarto_valuation_requests');
    }
};

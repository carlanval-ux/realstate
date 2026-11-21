<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Valoraciones: report format chosen in the module, and the delivery of each request (e-mail, WhatsApp). */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('clarto_valuation_settings', function (Blueprint $table): void {
            $table->string('report_format', 20)->nullable();
        });
        Schema::table('clarto_valuation_requests', function (Blueprint $table): void {
            $table->json('delivery')->nullable();   // {run, email: {...}, whatsapp: {...}, historial: [...]}
        });
    }

    public function down(): void
    {
        Schema::table('clarto_valuation_requests', fn (Blueprint $table) => $table->dropColumn('delivery'));
        Schema::table('clarto_valuation_settings', fn (Blueprint $table) => $table->dropColumn('report_format'));
    }
};

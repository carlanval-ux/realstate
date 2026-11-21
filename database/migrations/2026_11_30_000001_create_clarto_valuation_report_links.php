<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short public links to a valuation report (https://valoraciones.clarto.es/r/{codigo}): only the SHA-256 of the code
 * is stored, tied to the request and to the calculation (report version) it opens. docs/clarto/valoraciones.md.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_valuation_report_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('valuation_request_id')->constrained('clarto_valuation_requests')->cascadeOnDelete();
            $table->foreignId('valuation_run_id')->constrained('clarto_valuation_runs')->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->string('origin', 20);                     // first producer: email | whatsapp | ghl | panel
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('ghl_at')->nullable();          // written to GoHighLevel (informe_url) at
            $table->unsignedInteger('opens')->default(0);     // people, not link previews or mail scanners
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamps();
        });
        Schema::table('clarto_valuation_requests', function (Blueprint $table): void {
            // Set when the team revokes the report links: the signed links sent before the short ones stop working too.
            $table->timestamp('report_links_revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clarto_valuation_requests', fn (Blueprint $table) => $table->dropColumn('report_links_revoked_at'));
        Schema::dropIfExists('clarto_valuation_report_links');
    }
};

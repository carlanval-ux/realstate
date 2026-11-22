<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Retention (docs/clarto/conservacion-valoraciones.md): when the personal data of a valuation request was removed. */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('clarto_valuation_requests', function (Blueprint $table): void {
            $table->timestamp('anonymized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clarto_valuation_requests', fn (Blueprint $table) => $table->dropColumn('anonymized_at'));
    }
};

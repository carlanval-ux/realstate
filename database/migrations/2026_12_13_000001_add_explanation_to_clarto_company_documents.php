<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Documentación de la empresa» → expedientes: «Explicación», a free text (optional, ≤ 10 000 characters) saying what
 * the expediente is and why. Shown first on the record and searched by the list. The annotations stay the dated thread.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('clarto_company_documents', function (Blueprint $table): void {
            $table->text('explanation')->nullable()->after('subject');
        });
    }

    public function down(): void
    {
        Schema::table('clarto_company_documents', function (Blueprint $table): void {
            $table->dropColumn('explanation');
        });
    }
};

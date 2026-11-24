<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Disponible» (docs/clarto/gestor-documental.md): an expediente without approval no longer has a null status. Every
 * one with a null status becomes «disponible»; the others keep theirs. New ones get it from the model's default, so
 * the column stays as it is (nullable string). Going back turns «disponible» into null again.
 */
return new class() extends Migration
{
    public function up(): void
    {
        DB::table('clarto_company_documents')->whereNull('status')->update(['status' => 'disponible']);
    }

    public function down(): void
    {
        DB::table('clarto_company_documents')->where('status', 'disponible')->update(['status' => null]);
    }
};

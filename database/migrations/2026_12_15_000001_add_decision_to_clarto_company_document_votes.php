<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Documentación de la empresa» → three answers to an approval: «decision» (favor / condiciones / contra) replaces the
 * old «approved» flag: approved → favor, not approved → contra. Going back, favor and condiciones become approved.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('clarto_company_document_votes', function (Blueprint $table): void {
            $table->string('decision', 12)->nullable()->after('user_id');
        });
        DB::table('clarto_company_document_votes')->where('approved', true)->update(['decision' => 'favor']);
        DB::table('clarto_company_document_votes')->where('approved', false)->update(['decision' => 'contra']);
        Schema::table('clarto_company_document_votes', function (Blueprint $table): void {
            $table->dropColumn('approved');
        });
    }

    public function down(): void
    {
        Schema::table('clarto_company_document_votes', function (Blueprint $table): void {
            $table->boolean('approved')->default(false)->after('user_id');
        });
        DB::table('clarto_company_document_votes')->whereIn('decision', ['favor', 'condiciones'])->update(['approved' => true]);
        Schema::table('clarto_company_document_votes', function (Blueprint $table): void {
            $table->dropColumn('decision');
        });
    }
};

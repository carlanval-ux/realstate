<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Documentación de la empresa» → approval rule of an expediente: «numero» (a fixed number of approvals, what there was
 * until now: the default for the existing ones) or «mayoria» (absolute majority of the voters, worked out when the
 * approval is asked for and stored in approvals_required as before).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('clarto_company_documents', function (Blueprint $table): void {
            $table->string('approval_rule', 10)->default('numero')->after('approvals_required');
        });
    }

    public function down(): void
    {
        Schema::table('clarto_company_documents', function (Blueprint $table): void {
            $table->dropColumn('approval_rule');
        });
    }
};

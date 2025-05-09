<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('process_selections', function (Blueprint $table) {
            $table->integer('current_step')
                  ->default(1)
                  ->after('allowed_enem_years');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('process_selections', function (Blueprint $table) {
            //
        });
    }
};

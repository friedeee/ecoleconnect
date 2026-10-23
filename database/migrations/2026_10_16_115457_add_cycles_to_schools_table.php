<?php

use App\Enums\Cycle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->json('cycles')->nullable()->after('motto');
        });

        // Existing schools keep seeing every cycle, as before.
        DB::table('schools')->update(['cycles' => json_encode(array_column(Cycle::cases(), 'value'))]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('cycles');
        });
    }
};

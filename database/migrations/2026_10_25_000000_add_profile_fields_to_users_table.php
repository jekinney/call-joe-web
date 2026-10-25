<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who the user is, for the age gate, the admin area, and contacts that know their time of day and name.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('email');
            $table->boolean('is_admin')->default(false)->after('date_of_birth');
            $table->string('display_name', 60)->nullable()->after('name');
            $table->string('timezone', 64)->default('UTC')->after('is_admin');
            $table->string('locale', 10)->default('en')->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'is_admin', 'display_name', 'timezone', 'locale']);
        });
    }
};

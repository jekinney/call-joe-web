<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Admin access becomes a role with permissions instead of one yes/no flag. Everyone who was an admin becomes an Owner.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->json('permissions');
            $table->boolean('is_owner')->default(false);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('is_admin')->constrained()->nullOnDelete();
        });

        $now = now();
        $ownerId = DB::table('roles')->insertGetId([
            'name' => 'Owner', 'description' => 'Everything, including roles.', 'permissions' => '[]',
            'is_owner' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('roles')->insert([
            [
                'name' => 'Support', 'description' => 'Looks after users: sees them, changes plans, reviews blocked texts.',
                'permissions' => json_encode(['users.view', 'users.plan', 'safety.review']),
                'is_owner' => false, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'Content editor', 'description' => 'Writes contacts and looks after pictures.',
                'permissions' => json_encode(['contacts.manage', 'prompts.view', 'pictures.manage']),
                'is_owner' => false, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        DB::table('users')->where('is_admin', true)->update(['role_id' => $ownerId]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        DB::table('users')->whereNotNull('role_id')->update(['is_admin' => true]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });

        Schema::dropIfExists('roles');
    }
};

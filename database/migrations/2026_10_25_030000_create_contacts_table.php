<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contacts (the AI friends in the catalog) and every version of who they are.
     * persona_md is a contact's identity: it only changes through App\Services\ContactCatalog, which keeps each
     * version so any change can be rolled back.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 80);
            $table->string('tagline', 160)->nullable();
            $table->string('gender', 20);
            $table->text('persona_md');
            $table->text('looks')->nullable();
            $table->string('voice_provider_id', 120)->nullable();
            $table->jsonb('voice_settings')->nullable();
            $table->string('avatar_path')->nullable();
            $table->foreignId('required_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->boolean('is_published')->default(false)->index();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contact_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('persona_md');
            $table->text('looks')->nullable();
            $table->string('note', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['contact_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_versions');
        Schema::dropIfExists('contacts');
    }
};

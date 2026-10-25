<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pictures contacts send (App\Services\Pictures).
     *
     * - images: a stored picture (metadata stripped), on the images disk (config/contacts.php).
     * - image_requests: one picture a contact decided to send, from the [photo: ...] in their reply to the message it
     *   became. queued -> generating -> ready, or failed / blocked (by the safety checks).
     * - A rule every contact follows: how (and when not) to send a photo.
     */
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 20);
            $table->string('storage_path');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('bytes');
            $table->char('sha256', 64)->index();
            $table->float('nsfw_score')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('image_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('prompt', 300);          // what the contact wrote: "my lunch"
            $table->text('normalized_prompt');      // the full prompt sent to the provider
            $table->char('cache_key', 64)->nullable()->index(); // for the shared image cache (next phase)
            $table->string('provider', 20);
            $table->string('provider_job_id', 100)->nullable();
            $table->string('status', 12)->default('queued'); // queued | generating | ready | failed | blocked
            $table->string('error', 200)->nullable();
            $table->foreignId('image_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['user_contact_id', 'status']);
        });

        $now = now();
        DB::table('prompt_rules')->insert([
            'key' => 'photos', 'title' => 'Sending photos', 'sort' => 5, 'created_at' => $now, 'updated_at' => $now,
            'body_md' => "- In texts you can send a photo when it fits naturally (your lunch, your dog, the view from your porch). Put it on its own line as [photo: what it shows], at most one per message, and not often.\n- Never a photo of a child or anyone under 18, of a real or famous person, or anything explicit.",
        ]);
    }

    public function down(): void
    {
        DB::table('prompt_rules')->where('key', 'photos')->delete();
        Schema::dropIfExists('image_requests');
        Schema::dropIfExists('images');
    }
};

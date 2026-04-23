<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_decisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('report_id')->constrained('reports')->cascadeOnDelete();
            $table->string('grader');
            $table->string('tier');
            $table->string('verdict');
            $table->float('severity')->nullable();
            $table->text('reason')->nullable();
            $table->json('evidence')->nullable();
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['report_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_decisions');
    }
};

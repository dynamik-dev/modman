<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('reportable_type');
            $table->string('reportable_id');
            $table->string('reporter_type')->nullable();
            $table->string('reporter_id')->nullable();
            $table->string('reason')->nullable();
            $table->string('state');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['reportable_type', 'reportable_id']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};

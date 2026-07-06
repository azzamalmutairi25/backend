<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_competency', function (Blueprint $table) {
            $table->id();
            $table->string('activity');
            $table->foreignId('competency_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['activity', 'competency_id']);
            $table->index('activity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_competency');
    }
};

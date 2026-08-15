<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cid10', function (Blueprint $table) {
            $table->string('code')->primary();
            $table->string('description');
            $table->string('category', 3)->index();
            $table->string('chapter')->nullable();
            $table->string('normalized_description')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cid10');
    }
};

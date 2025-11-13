<?php

use Phaseolies\Support\Facades\Schema;
use Phaseolies\Database\Migration\Migration;
use Phaseolies\Database\Migration\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('queue_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedInteger('reserved_at')->index()->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->unsignedInteger('failed_at')->index();
        });
    }

    /**
     * Reverse the migrations
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_jobs');
        Schema::dropIfExists('failed_jobs');
    }
};

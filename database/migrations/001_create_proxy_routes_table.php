<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('proxy_routes')) {
            Schema::create('proxy_routes', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('server_id')->unique();
                $table->string('subdomain')->unique();
                $table->timestamps();

                $table->foreign('server_id')
                    ->references('id')
                    ->on('servers')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_routes');
    }
};

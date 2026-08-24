<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table): void {
            $table->string('media_type', 20)->nullable()->after('message');
            $table->string('media_disk', 40)->nullable()->after('media_type');
            $table->text('media_path')->nullable()->after('media_disk');
            $table->text('telegram_file_id')->nullable()->after('media_path');
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table): void {
            $table->dropColumn(['media_type', 'media_disk', 'media_path', 'telegram_file_id']);
        });
    }
};

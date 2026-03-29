<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('sms.drivers.log.table', 'sms_log');

        Schema::create($table, function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->string('to');
            $blueprint->string('from');
            $blueprint->text('body');
            $blueprint->json('options')->nullable();
            $blueprint->timestamp('logged_at');
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        $table = config('sms.drivers.log.table', 'sms_log');

        Schema::dropIfExists($table);
    }
};

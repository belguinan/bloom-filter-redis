<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table): void {
            $table->string('email', 254)
                ->charset('ascii')
                ->collation('ascii_bin')
                ->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
           
            $table->bigInteger('telegram_id')->unique()->nullable()->after('id');
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->string('first_name')->after('telegram_id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('username')->nullable()->after('last_name');
            $table->string('language_code', 10)->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_admin')->default(false);
            $table->string('state')->nullable();
            $table->text('state_data')->nullable();
            $table->text('message_ids')->nullable();
            $table->text('cart')->nullable();
            $table->text('favorites')->nullable();
            $table->string('shipping_name')->nullable();
            $table->string('shipping_phone')->nullable();
            $table->text('shipping_address')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'telegram_id', 'first_name', 'last_name', 'username', 'language_code', 
                'status', 'is_admin', 'state', 'state_data', 'message_ids', 'cart', 
                'favorites', 'shipping_name', 'shipping_phone', 'shipping_address'
            ]);

            // Revert changes to default columns
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
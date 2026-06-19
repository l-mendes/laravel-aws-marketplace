<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->string('agreement_id')->unique();
            $table->string('license_arn')->nullable()->index();
            $table->string('product_code')->nullable();
            $table->string('customer_account_id')->nullable()->index();
            $table->string('customer_identifier')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->nullableMorphs('owner');
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('marketplace-aws.persistence.table', 'aws_marketplace_subscriptions');
    }
};

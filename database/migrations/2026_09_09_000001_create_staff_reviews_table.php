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
        Schema::create('staff_reviews', function (Blueprint $table) {
            $table->id();

            // Historical: organization/restaurant/staff links must never
            // cascade-delete a review out of existence.
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_user_id')->constrained('users')->restrictOnDelete();

            // The reviewer may leave the company; the review (and its
            // rating/comment) must remain.
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();

            $table->timestamps();

            $table->index(['restaurant_id', 'staff_user_id']);
            $table->index(['staff_user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_reviews');
    }
};

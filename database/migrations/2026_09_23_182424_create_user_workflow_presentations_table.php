<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("user_workflow_presentations", function (Blueprint $table) {
            $table->id();
            $table->foreignId("user_id")->constrained("users")->cascadeOnDelete();
            $table->string("option_type", 50);
            $table->string("canonical_key", 80);
            $table->string("display_label", 150)->nullable();
            $table->boolean("is_visible")->default(true);
            $table->unsignedInteger("sort_order")->nullable();
            $table->timestamps();

            $table->unique(["user_id", "option_type", "canonical_key"], "uwp_user_type_key_unique");
            $table->index(["option_type", "canonical_key"], "uwp_type_key_index");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("user_workflow_presentations");
    }
};

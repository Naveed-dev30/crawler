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
    Schema::table('filters', function (Blueprint $table) {
      $table->boolean('useminfix')->default(false);
      $table->boolean('useminhour')->default(false);
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::table('filters', function (Blueprint $table) {
      $table->dropColumn('useminfix');
      $table->dropColumn('useminhour');
    });
  }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Поля для протоколов МЭ и технологии (источники колонок конструктора).
 *  profile / practice_types — технология, вводит школьный оператор, наследуется в МЭ;
 *  primary_score / appeal_addition / final_score — баллы МЭ (итоговый = первичный + апелляция);
 *  prev_higher_stage_winner — призёр рег./респ. этапа прошлого года (минуя ШЭ и МЭ);
 *  question_scores / question_appeals — баллы по вопросам (МЭ, переменное число) в JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('human_olympiad', function (Blueprint $table) {
            $table->string('profile')->nullable()->after('teacher_workplace');
            $table->string('practice_types')->nullable()->after('profile');
            $table->float('primary_score')->nullable()->after('practice_types');
            $table->float('appeal_addition')->nullable()->after('primary_score');
            $table->float('final_score')->nullable()->after('appeal_addition');
            $table->boolean('prev_higher_stage_winner')->default(false)->after('final_score');
            $table->json('question_scores')->nullable()->after('prev_higher_stage_winner');
            $table->json('question_appeals')->nullable()->after('question_scores');
        });
    }

    public function down(): void
    {
        Schema::table('human_olympiad', function (Blueprint $table) {
            $table->dropColumn([
                'profile', 'practice_types', 'primary_score', 'appeal_addition', 'final_score',
                'prev_higher_stage_winner', 'question_scores', 'question_appeals',
            ]);
        });
    }
};

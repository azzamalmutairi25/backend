<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ActivityCompetencySeeder extends Seeder
{
    public function run(): void
    {
        $map = [
            'interview'  => [1, 3, 4, 7, 8],
            'discussion' => [1, 2, 5, 6],
        ];

        foreach ($map as $activity => $competencyIds) {
            foreach ($competencyIds as $competencyId) {
                DB::table('activity_competency')->updateOrInsert(
                    ['activity' => $activity, 'competency_id' => $competencyId],
                    ['updated_at' => now(), 'created_at' => now()]
                );
            }
        }
    }
}

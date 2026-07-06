<?php

namespace App\Http\Controllers;

use App\Models\Sector;

class SectorController extends Controller
{
    public function index()
    {
        $sectors = Sector::orderBy('name_ar')->get()->map(fn ($s) => [
            'id' => $s->id,
            'code' => $s->code,
            'nameAr' => $s->name_ar,
            'isMilitary' => $s->is_military,
        ]);
        return response()->json(['sectors' => $sectors]);
    }
}

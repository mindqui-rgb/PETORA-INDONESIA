<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class CustomerPortalController extends Controller
{
    public function index(Request $request): View
    {
        $customerId = DB::table('customers')
            ->where('user_id', $request->user()->getKey())
            ->value('id');

        $pets = $customerId === null
            ? collect()
            : DB::table('pet_guardians')
                ->join('pets', 'pets.id', '=', 'pet_guardians.pet_id')
                ->where('pet_guardians.customer_id', $customerId)
                ->whereNull('pet_guardians.ended_at')
                ->orderBy('pets.name')
                ->get([
                    'pets.id',
                    'pets.name',
                    'pets.species',
                    'pets.breed',
                    'pet_guardians.is_primary',
                ]);

        return view('customer.dashboard', ['pets' => $pets]);
    }
}

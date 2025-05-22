<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Movie;
use App\Models\Session;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

class StatisticsController extends Controller
{
    /**
     * 
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function overview()
    {
        $statistics = [
            'users' => User::count(),
            'movies' => Movie::count(),
            'sessions' => Session::count(),
            'tickets' => [
                'total' => Ticket::count(),
                'booked' => Ticket::where('status', 'booked')->count(),
                'paid' => Ticket::where('status', 'paid')->count(),
                'canceled' => Ticket::where('status', 'canceled')->count(),
                'expired' => Ticket::where('status', 'expired')->count(),
            ],
            'revenue' => [
                'total' => Ticket::where('status', 'paid')->sum('price'),
            ]
        ];

        return response()->json([
            'status' => 'success',
            'data' => $statistics
        ]);
    }
}

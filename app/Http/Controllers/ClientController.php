<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index()
    {
        return response()->json(Client::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:1000',
        ]);

        $client = Client::create($validated);

        \App\Models\ActivityLog::log(
            'Client Created',
            "Client {$client->name} was created.",
            ['client_id' => $client->id]
        );

        return response()->json($client, 201);
    }

    public function show(Client $client)
    {
        return response()->json($client);
    }

    public function update(Request $request, Client $client)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:1000',
        ]);

        $client->update($validated);

        \App\Models\ActivityLog::log(
            'Client Updated',
            "Client {$client->name} was updated.",
            ['client_id' => $client->id]
        );

        return response()->json($client);
    }

    public function destroy(Client $client)
    {
        $clientName = $client->name;
        $clientId = $client->id;
        $client->delete();

        \App\Models\ActivityLog::log(
            'Client Deleted',
            "Client {$clientName} was deleted.",
            ['client_id' => $clientId]
        );

        return response()->json(null, 204);
    }
}

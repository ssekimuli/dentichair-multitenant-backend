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
            'email' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:1000',
        ]);

        $data = [
            'name' => $validated['name'],
            'email' => !empty($validated['email']) ? $validated['email'] : null,
            'phone' => !empty($validated['phone']) ? $validated['phone'] : null,
            'address' => !empty($validated['address']) ? $validated['address'] : null,
        ];

        $client = Client::create($data);

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
            'email' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:1000',
        ]);

        if (array_key_exists('email', $validated) && empty($validated['email'])) {
            $validated['email'] = null;
        }
        if (array_key_exists('phone', $validated) && empty($validated['phone'])) {
            $validated['phone'] = null;
        }
        if (array_key_exists('address', $validated) && empty($validated['address'])) {
            $validated['address'] = null;
        }

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
<?php

namespace App\Http\Controllers;

class DownloadController extends Controller
{
    public function printerDriver()
    {
        $path = public_path('printerdrive/bluetooth printer driver.exe');

        if (!file_exists($path)) {
            return response()->json(['error' => 'Printer driver not found.'], 404);
        }

        return response()->download($path);
    }
}

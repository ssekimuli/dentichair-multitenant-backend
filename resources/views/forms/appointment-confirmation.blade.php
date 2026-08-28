@extends('layout')

@section('title', 'Appointment Confirmed - ' . config('app.name'))

@section('og_type', 'website')
@section('og_title', 'Your Appointment is Confirmed!')
@section('og_description', 'Your appointment with ' . $appointment->doctor_name . ' has been confirmed for ' . \Carbon\Carbon::parse($appointment->date)->format('F d, Y'))
@section('og_url', url("/appointment/{$appointment->confirmation_token}/confirmation"))
@section('og_image', asset('ic_launcher.png'))

@section('twitter_card', 'summary')
@section('twitter_title', 'Appointment Confirmed')
@section('twitter_description', 'Your appointment has been successfully booked')
@section('twitter_image', asset('ic_launcher.png'))

@section('meta_description', 'Your appointment with ' . $appointment->doctor_name . ' is confirmed. Token: ' . $appointment->token_number)

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="bg-white rounded-lg shadow">
        <!-- Success Header -->
        <div class="px-6 py-4 bg-green-50 border-b border-green-200 rounded-t-lg">
            <div class="flex items-center">
                <svg class="h-6 w-6 text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                <h2 class="ml-3 text-2xl font-bold text-green-900">Appointment Confirmed!</h2>
            </div>
            <p class="mt-2 text-sm text-green-700">Your appointment has been successfully booked.</p>
        </div>

        <!-- Confirmation Details -->
        <div class="px-6 py-8">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- Patient Information -->
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Patient Information</h3>
                    <div class="bg-gray-50 rounded-lg p-4 space-y-3">
                        <div>
                            <p class="text-sm text-gray-600">Name</p>
                            <p class="text-lg font-medium text-gray-900">{{ $appointment->patient->first_name }} {{ $appointment->patient->last_name }}</p>
                        </div>
                        @if ($appointment->patient->email)
                            <div>
                                <p class="text-sm text-gray-600">Email</p>
                                <p class="text-sm text-gray-900">{{ $appointment->patient->email }}</p>
                            </div>
                        @endif
                        @if ($appointment->patient->phone)
                            <div>
                                <p class="text-sm text-gray-600">Phone</p>
                                <p class="text-sm text-gray-900">{{ $appointment->patient->phone }}</p>
                            </div>
                        @endif
                        @if ($appointment->patient->gender)
                            <div>
                                <p class="text-sm text-gray-600">Gender</p>
                                <p class="text-sm text-gray-900">{{ $appointment->patient->gender }}</p>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Appointment Information -->
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Appointment Details</h3>
                    <div class="bg-indigo-50 rounded-lg p-4 space-y-3">
                        <div>
                            <p class="text-sm text-gray-600">Doctor</p>
                            <p class="text-lg font-medium text-gray-900">{{ $appointment->doctor_name }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Treatment</p>
                            <p class="text-sm text-gray-900">{{ $appointment->treatment }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Date</p>
                            <p class="text-sm text-gray-900">{{ \Carbon\Carbon::parse($appointment->date)->format('F d, Y') }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Time</p>
                            <p class="text-sm text-gray-900">{{ \Carbon\Carbon::parse($appointment->time, 'UTC')->format('h:i A') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Info -->
            <div class="mt-8 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <h4 class="font-semibold text-blue-900 mb-2">Next Steps</h4>
                <ul class="text-sm text-blue-800 space-y-1">
                    <li>✓ Your appointment has been confirmed</li>
                    <li>✓ A confirmation email will be sent shortly</li>
                    <li>✓ Please arrive 10 minutes before your appointment</li>
                    <li>✓ Contact us if you need to reschedule</li>
                </ul>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="px-6 py-4 bg-gray-50 border-t flex justify-between rounded-b-lg">
            <a href="{{ route('book.appointment') }}"
                class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                Book Another Appointment
            </a>
            <a href="/"
                class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                Return Home
            </a>
        </div>
    </div>
</div>
@endsection

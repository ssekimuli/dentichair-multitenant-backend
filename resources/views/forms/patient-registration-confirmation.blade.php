@extends('layout')

@section('title', 'Registration Confirmed - ' . config('app.name'))

@section('og_type', 'website')
@section('og_title', 'Registration Complete!')
@section('og_description', 'Welcome to ' . config('app.name') . '! Your patient account has been successfully created.')
@section('og_url', url('/'))
@section('og_image', asset('ic_launcher.png'))

@section('twitter_card', 'summary')
@section('twitter_title', 'Registration Complete')
@section('twitter_description', 'Your patient account has been successfully created')
@section('twitter_image', asset('ic_launcher.png'))

@section('meta_description', 'Your registration with ' . config('app.name') . ' is complete. You can now book appointments.')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="bg-white rounded-lg shadow">
        <!-- Success Header -->
        <div class="px-6 py-4 bg-green-50 border-b border-green-200 rounded-t-lg">
            <div class="flex items-center">
                <svg class="h-6 w-6 text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                <h2 class="ml-3 text-2xl font-bold text-green-900">Registration Complete!</h2>
            </div>
            <p class="mt-2 text-sm text-green-700">Welcome to {{ config('app.name') }}. Your account has been successfully created.</p>
        </div>

        <!-- Registration Details -->
        <div class="px-6 py-8">
            <!-- Welcome Message -->
            <div class="mb-8">
                <p class="text-lg text-gray-700 mb-4">
                    Hello <strong>{{ $patient->first_name }} {{ $patient->last_name }}</strong>,
                </p>
                <p class="text-gray-600">
                    Thank you for registering with us! Your patient account is now active and ready to use.
                    You can now book appointments online, view your dental records, and manage your health information.
                </p>
            </div>

            <!-- Patient Information -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Your Information</h3>
                    <div class="bg-gray-50 rounded-lg p-4 space-y-3">
                        <div>
                            <p class="text-sm text-gray-600">Name</p>
                            <p class="text-lg font-medium text-gray-900">{{ $patient->first_name }} {{ $patient->last_name }}</p>
                        </div>
                        @if ($patient->email)
                            <div>
                                <p class="text-sm text-gray-600">Email</p>
                                <p class="text-sm text-gray-900">{{ $patient->email }}</p>
                            </div>
                        @endif
                        <div>
                            <p class="text-sm text-gray-600">Phone</p>
                            <p class="text-sm text-gray-900">{{ $patient->phone }}</p>
                        </div>
                        @if ($patient->gender)
                            <div>
                                <p class="text-sm text-gray-600">Gender</p>
                                <p class="text-sm text-gray-900">{{ $patient->gender }}</p>
                            </div>
                        @endif
                        @if ($patient->date_of_birth)
                            <div>
                                <p class="text-sm text-gray-600">Date of Birth</p>
                                <p class="text-sm text-gray-900">{{ $patient->date_of_birth->format('F d, Y') }}</p>
                            </div>
                        @endif
                    </div>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Account Details</h3>
                    <div class="bg-indigo-50 rounded-lg p-4 space-y-3">
                        <div>
                            <p class="text-sm text-gray-600">Preferred Branch</p>
                            <p class="text-lg font-medium text-gray-900">{{ $patient->branch->name }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Account Status</p>
                            <p class="text-sm">
                                <span class="inline-block px-3 py-1 bg-green-100 text-green-800 text-xs font-semibold rounded-full">
                                    {{ $patient->status }}
                                </span>
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Patient ID</p>
                            <p class="text-sm text-gray-900 font-mono">{{ $patient->id }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Next Steps -->
            <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg mb-8">
                <h4 class="font-semibold text-blue-900 mb-3">What's Next?</h4>
                <ol class="text-sm text-blue-800 space-y-2">
                    <li class="flex">
                        <span class="mr-3 font-semibold">1.</span>
                        <span><strong>Book an Appointment</strong> - Use the appointment booking system to schedule your visit</span>
                    </li>
                    <li class="flex">
                        <span class="mr-3 font-semibold">2.</span>
                        <span><strong>Save Appointment Confirmation</strong> - Keep your appointment confirmation for reference</span>
                    </li>
                    <li class="flex">
                        <span class="mr-3 font-semibold">3.</span>
                        <span><strong>Arrive Early</strong> - Please arrive 10 minutes before your scheduled appointment</span>
                    </li>
                    <li class="flex">
                        <span class="mr-3 font-semibold">4.</span>
                        <span><strong>View Your Records</strong> - Your dental records will be available after your first visit</span>
                    </li>
                </ol>
            </div>

            <!-- Important Information -->
            <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg">
                <h4 class="font-semibold text-amber-900 mb-2">Important Information</h4>
                <ul class="text-sm text-amber-800 space-y-1">
                    <li>• Your information is securely stored and protected</li>
                    <li>• You can book appointments 24/7 online</li>
                    <li>• Confirmation details will be sent via email and SMS</li>
                    <li>• Contact us if you need to make changes to your information</li>
                </ul>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="px-6 py-4 bg-gray-50 border-t flex justify-between rounded-b-lg">
            <a href="/"
                class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                Return Home
            </a>
            <a href="{{ route('book.appointment') }}"
                class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                Book an Appointment
            </a>
        </div>
    </div>
</div>
@endsection

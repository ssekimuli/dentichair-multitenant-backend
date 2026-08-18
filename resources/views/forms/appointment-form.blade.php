@extends('layout')

@section('title', 'Create Appointment')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-2xl font-bold text-gray-900">Create Appointment</h2>
            <p class="mt-1 text-sm text-gray-500">Schedule a new appointment</p>
        </div>

        <form action="{{ route('appointment.store') }}" method="POST" class="p-6 space-y-6">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Patient -->
                <div>
                    <label for="patient_id" class="block text-sm font-medium text-gray-700">Patient *</label>
                    <select name="patient_id" id="patient_id"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 border px-3 py-2"
                        required onchange="updatePatientName()">
                        <option value="">Select Patient</option>
                        @foreach ($patients as $patient)
                            <option value="{{ $patient->id }}" data-name="{{ $patient->first_name }} {{ $patient->last_name }}"
                                {{ old('patient_id') == $patient->id ? 'selected' : '' }}>
                                {{ $patient->first_name }} {{ $patient->last_name }} ({{ $patient->email ?? 'N/A' }})
                            </option>
                        @endforeach
                    </select>
                    @error('patient_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Patient Name (Auto-filled) -->
                <div>
                    <label for="patient_name" class="block text-sm font-medium text-gray-700">Patient Name *</label>
                    <input type="text" name="patient_name" id="patient_name" value="{{ old('patient_name') }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 border px-3 py-2"
                        readonly>
                    @error('patient_name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Doctor -->
                <div>
                    <label for="doctor_id" class="block text-sm font-medium text-gray-700">Doctor *</label>
                    <select name="doctor_id" id="doctor_id"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 border px-3 py-2"
                        required onchange="updateDoctorName()">
                        <option value="">Select Doctor</option>
                        @foreach ($doctors as $doctor)
                            <option value="{{ $doctor->id }}" data-name="{{ $doctor->name }}"
                                {{ old('doctor_id') == $doctor->id ? 'selected' : '' }}>
                                {{ $doctor->name }} ({{ $doctor->email ?? 'N/A' }})
                            </option>
                        @endforeach
                    </select>
                    @error('doctor_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Doctor Name (Auto-filled) -->
                <div>
                    <label for="doctor_name" class="block text-sm font-medium text-gray-700">Doctor Name *</label>
                    <input type="text" name="doctor_name" id="doctor_name" value="{{ old('doctor_name') }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 border px-3 py-2"
                        readonly>
                    @error('doctor_name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Treatment -->
                <div class="md:col-span-2">
                    <label for="treatment" class="block text-sm font-medium text-gray-700">Treatment *</label>
                    <input type="text" name="treatment" id="treatment" value="{{ old('treatment') }}"
                        placeholder="e.g., Root Canal, Cleaning, etc."
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 border px-3 py-2"
                        required>
                    @error('treatment')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Date -->
                <div>
                    <label for="date" class="block text-sm font-medium text-gray-700">Date *</label>
                    <input type="date" name="date" id="date" value="{{ old('date') }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 border px-3 py-2"
                        required>
                    @error('date')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Time -->
                <div>
                    <label for="time" class="block text-sm font-medium text-gray-700">Time *</label>
                    <input type="time" name="time" id="time" value="{{ old('time') }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 border px-3 py-2"
                        required>
                    @error('time')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Branch -->
                <div>
                    <label for="branch_id" class="block text-sm font-medium text-gray-700">Branch *</label>
                    <select name="branch_id" id="branch_id"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 border px-3 py-2"
                        required>
                        <option value="">Select Branch</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" {{ old('branch_id') == $branch->id ? 'selected' : '' }}>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('branch_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Token Number -->
                <div>
                    <label for="token_number" class="block text-sm font-medium text-gray-700">Token Number</label>
                    <input type="text" name="token_number" id="token_number" value="{{ old('token_number') }}"
                        placeholder="Optional"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 border px-3 py-2">
                    @error('token_number')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Buttons -->
            <div class="flex justify-end space-x-3 pt-6 border-t">
                <a href="{{ route('appointment.create') }}"
                    class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    Cancel
                </a>
                <button type="submit"
                    class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                    Create Appointment
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function updatePatientName() {
    const select = document.getElementById('patient_id');
    const option = select.options[select.selectedIndex];
    document.getElementById('patient_name').value = option.getAttribute('data-name') || '';
}

function updateDoctorName() {
    const select = document.getElementById('doctor_id');
    const option = select.options[select.selectedIndex];
    document.getElementById('doctor_name').value = option.getAttribute('data-name') || '';
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updatePatientName();
    updateDoctorName();
});
</script>
@endsection

@component('mail::message')
# Periodontal Examination Results

Hello {{ $name }},

Here is the summary of your recent periodontal examination at {{ $clinicName }}:

@if(!empty($customMessage))
**Note from the Clinic:**
{{ $customMessage }}
@endif

---

### Key Indices:
- **Bleeding Index:** {{ $data['bleeding_index'] }}%
- **Plaque Index:** {{ $data['plaque_index'] }}%

### PSR Scores by Sextant:
- **Upper Right:** {{ $data['psr_scores'][0] }}
- **Upper Anterior:** {{ $data['psr_scores'][1] }}
- **Upper Left:** {{ $data['psr_scores'][2] }}
- **Lower Right:** {{ $data['psr_scores'][3] }}
- **Lower Anterior:** {{ $data['psr_scores'][4] }}
- **Lower Left:** {{ $data['psr_scores'][5] }}

---

Thank you,
{{ $clinicName }}
@endcomponent

<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('famie.frontend_url')">Famie</x-mail::header>
</x-slot:header>

# {{ $title }}

{{ $messageText }}

@if ($actionUrl)
<x-mail::button :url="$actionUrl">
{{ $title }}
</x-mail::button>

{{ __('mail.ignore_link') }}
@else
{{ __('mail.unrecognized_action') }}
@endif

Famie
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} Famie. {{ __('mail.rights') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>

<x-mail::message>
# {{ $title }}

{{ $messageText }}

@if ($actionUrl)
<x-mail::button :url="$actionUrl">
{{ $title }}
</x-mail::button>

このメールに心当たりがない場合は、リンクを操作せず破棄してください。
@else
この操作に心当たりがない場合は、ログイン画面からパスワードを再設定してください。
@endif

Famie
</x-mail::message>

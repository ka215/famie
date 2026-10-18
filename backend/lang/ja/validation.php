<?php

return [
    'required' => ':attributeを入力してください。',
    'string' => ':attributeは文字列で入力してください。',
    'integer' => ':attributeは整数で入力してください。',
    'numeric' => ':attributeは数値で入力してください。',
    'boolean' => ':attributeはONまたはOFFで指定してください。',
    'array' => ':attributeは配列で指定してください。',
    'email' => ':attributeには有効なメールアドレスを入力してください。',
    'in' => '選択された:attributeが不正です。',
    'unique' => 'この:attributeは既に使用されています。',
    'exists' => '選択された:attributeが存在しません。',
    'regex' => ':attributeの形式が不正です。',
    'confirmed' => ':attributeの確認用入力が一致しません。',
    'date' => ':attributeには有効な日付を入力してください。',
    'date_format' => ':attributeは:format形式で入力してください。',
    'max' => [
        'string' => ':attributeは:max文字以内で入力してください。',
        'numeric' => ':attributeは:max以下で入力してください。',
        'array' => ':attributeは:max件以内で指定してください。',
        'file' => ':attributeは:maxキロバイト以下にしてください。',
    ],
    'min' => [
        'string' => ':attributeは:min文字以上で入力してください。',
        'numeric' => ':attributeは:min以上で入力してください。',
        'array' => ':attributeは:min件以上で指定してください。',
        'file' => ':attributeは:minキロバイト以上にしてください。',
    ],
    'size' => [
        'string' => ':attributeは:size文字で入力してください。',
        'numeric' => ':attributeは:sizeにしてください。',
        'array' => ':attributeは:size件で指定してください。',
        'file' => ':attributeは:sizeキロバイトにしてください。',
    ],
    'attributes' => [
        'login' => 'ログインID', 'username' => 'ログインID', 'password' => 'パスワード',
        'new_password' => '新しいパスワード', 'current_password' => '現在のパスワード',
        'group_name' => '家族名', 'display_name' => '表示名', 'name' => '名前',
        'email' => 'メールアドレス', 'ui_locale' => '言語', 'show_name_suffix' => '敬称の表示',
        'role' => '役割', 'status' => '所属状態', 'color_code' => '色', 'id' => 'ID', 'token' => 'トークン',
    ],
];

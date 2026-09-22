# How to manage seed users

1. Re-seeding

  - If it's okay to delete the data...: `php artisan migrate:fresh --seed`
  - If it's not the data deleting...: run `php artisan tinker` then `App\Models\User::truncate();` and `php artisan db:seed --class=UserSeeder`

2. How to change the seed user's password in Laravel

```php
php artisan tinker --execute="App\Models\User::where('username','parent1')->first()->update(['password'=>Hash::make('NEW_THIS_USER_PASSWORD')]); App\Models\User::where('username','child1')->first()->update(['password'=>Hash::make('NEW_THIS_USER_PASSWORD')]);"
```

3. How to change specific username in Laravel

```php
php artisan tinker --execute='App\Models\User::where("username", "admFamie")->firstOrFail()->update(["username" => "NEW_USERNAME"]);'
```

4. How to change specific display name in Laravel

```php
php artisan tinker --execute='App\Models\User::where("id", 1)->firstOrFail()->update(["display_name" => "NEW_DISPLAY_NAME"]);'
```

5. Check user list

```php
php artisan tinker --execute='dump(App\Models\User::query()->get(["id", "username", "display_name", "role"])->toArray());'
```

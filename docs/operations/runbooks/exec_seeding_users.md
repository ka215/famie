# How to manage seed users

1. Re-seeding

  - If it's okay to delete the data...: `php artisan migrate:fresh --seed`
  - If it's not the data deleting...: run `php artisan tinker` then `App\Models\User::truncate();` and `php artisan db:seed --class=UserSeeder`

2. How to change the seed user's password in Laravel

```php
php artisan tinker --execute="App\Models\User::where('username','parent1')->first()->update(['password'=>Hash::make('NEW_THIS_USER_PASSWORD')]); App\Models\User::where('username','child1')->first()->update(['password'=>Hash::make('NEW_THIS_USER_PASSWORD')]);"
```

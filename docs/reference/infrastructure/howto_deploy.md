Laravelにおける**IP制限ミドルウェアの実装コード**と、コアサーバーVPS（Ubuntu / Debian想定）での**本番デプロイ・Nginx・SSL（Let's Encrypt）設定手順**を作成いたしました。

自宅からのアクセスのみを通過させ、Nuxt 4 と Laravel API を同一VPS上で連携させる安全かつシンプルな本番構成となっています。

---

# 1. Laravel IP制限ミドルウェアの実装

自宅のグローバルIPからのアクセスのみを許可し、それ以外のアクセスに対しては `403 Forbidden` を返却（または接続拒否）するミドルウェアを作成します。

### ① ミドルウェアの作成 (`app/Http/Middleware/RestrictIpAddress.php`)

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictIpAddress
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // .env から許可するIPリストを取得（カンマ区切りで複数設定可能）
        $allowedIps = array_filter(explode(',', env('ALLOWED_IPS', '')));

        // リバースプロキシ（Nginx）経由のクライアントIPを取得
        $clientIp = $request->ip();

        // 許可リストが空の場合、またはIPが一致しない場合は遮断
        if (empty($allowedIps) || ! in_array($clientIp, $allowedIps, true)) {
            // セキュリティ向上のため、あえて404を返すことも可能
            abort(403, 'Access denied: Your IP address is not authorized.');
        }

        return $next($request);
    }
}

```

### ② ミドルウェアの登録 (`bootstrap/app.php`)

Laravel 11 / 12 以降の構造に準拠し、グローバルミドルウェアとして登録します。

```php
<?php

use App\Http\Middleware\RestrictIpAddress;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // グローバルミドルウェアとして全リクエストにIP制限を適用
        $middleware->append(RestrictIpAddress::class);

        // NginxなどのリバースプロキシからのIP取得を正常化
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

```

### ③ 環境変数の設定 (`.env`)

本番環境の `.env` にご自宅のグローバルIPを設定します。

```env
# 自宅のグローバルIPアドレスを設定（複数の場合はカンマ区切り）
ALLOWED_IPS=123.45.67.89,123.45.67.90

```

---

# 2. 本番環境アーキテクチャ概要

* **ドメイン構成**: `familog.ka2.org` (例)
* **Nuxt 4 (SSR/Node.js)**: PM2 で ポート `3000` で常駐実行
* **Laravel 8.4+ (PHP-FPM)**: ポート `9000` または UNIXソケットで実行
* **PostgreSQL 14.13+**: ローカルDBサーバー（Port 5432）
* **Nginx**: リバースプロキシ 兼 SSL終端 兼 静的ファイル配信

```
[クライアント (スマホ)] ──(HTTPS:443)──▶ [Nginx (IP制限/SSL)]
                                                │
                 ┌──────────────────────────────┴──────────────────────────────┐
                 ▼ (パス: /api/*)                                              ▼ (パス: /*)
        [Laravel (PHP-FPM:9000)]                                     [Nuxt 4 (PM2:3000)]
                 │                                                             │
                 ▼                                                             ▼
         [PostgreSQL DB]                                              [Vue SSR Rendering]

```

---

# 3. コアサーバーVPS 本番デプロイ・Nginx/SSL設定手順

1. **1. サーバー基本セットアップ & パッケージインストール:** コアサーバーVPS (Ubuntu/Debian) にSSH接続して実行.
必要なミドルウェア（PHP 8.4, Node.js 22+, PostgreSQL 14+, Nginx, Certbot）をインストールします。

```bash
# パッケージ更新
sudo apt update && sudo apt upgrade -y

# PHP 8.4 関連のインストール
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.4-fpm php8.4-cli php8.4-pgsql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip composer

# Node.js 22 LTS & PM2 (Nuxt実行用) のインストール
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
sudo npm install -y -g pm2

# PostgreSQL 14+ & Nginx & Certbot のインストール
sudo apt install -y postgresql postgresql-contrib nginx certbot python3-certbot-nginx

```


2. **2. PostgreSQL データベース作成:** データベースと接続ユーザーを設定.
アプリ用のデータベースとユーザーを作成します。

```bash
sudo -u postgres psql

```

```sql
-- PostgreSQL コンソール内で実行
CREATE DATABASE familog_db;
CREATE USER familog_user WITH ENCRYPTED PASSWORD 'your_secure_password';
GRANT ALL PRIVILEGES ON DATABASE familog_db TO familog_user;
\q

```


3. **3. Laravel バックエンドのセットアップ:**
`/var/www/familog-api` にLaravelプロジェクトを配置し、初期化します。

```bash
# ディレクトリ作成と権限設定
sudo mkdir -p /var/www/familog-api
sudo chown -R $USER:www-data /var/www/familog-api
cd /var/www/familog-api

# コードを配置後、依存関係をインストール
composer install --no-dev --optimize-autoloader

# 環境変数設定 (.env)
cp .env.example .env
nano .env

```

**.env 設定例**:

```env
APP_NAME="Family Activity Log API"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://familog.ka2.org

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=familog_db
DB_USERNAME=familog_user
DB_PASSWORD=your_secure_password

# 自宅IPアドレスを設定
ALLOWED_IPS=123.45.67.89

```

```bash
# キー生成・マイグレーション・キャッシュ最適化
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache

# ストレージのパーミッション調整
sudo chown -R www-data:www-data /var/www/familog-api/storage /var/www/familog-api/bootstrap/cache

```


4. **4. Nuxt 4 フロントエンドのセットアップ & PM2起動:**
`/var/www/familog-front` にNuxtプロジェクトを配置・ビルドし、PM2でプロセス化します。

```bash
sudo mkdir -p /var/www/familog-front
sudo chown -R $USER:$USER /var/www/familog-front
cd /var/www/familog-front

# 依存関係インストール & 本番ビルド
npm install
NUXT_PUBLIC_API_BASE=https://familog.ka2.org/api/v1 npm run build

# PM2 で Nuxt アプリをバックグラウンド起動
pm2 start .output/server/index.mjs --name "familog-nuxt"
pm2 save
pm2 startup

```


5. **5. Nginx 設定ファイルの作成:** Nginxでルーティングとリバースプロキシを構築.
`/etc/nginx/sites-available/familog.ka2.org` を作成します。

```bash
sudo nano /etc/nginx/sites-available/familog.ka2.org

```

**設定内容**:

```nginx
server {
    server_name familog.ka2.org;

    root /var/www/familog-api/public;
    index index.php;

    # NginxレベルでのIP制限 (必要に応じて二重でかける場合)
    # allow 123.45.67.89;
    # deny all;

    # 1. バックエンド API (/api) へのアクセス処理
    location /api {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # 2. フロントエンド (Nuxt 4 PM2:3000) へのリバースプロキシ
    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_cache_bypass $http_upgrade;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # セキュリティヘッダー
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
}

```

```bash
# 設定の有効化と構文チェック
sudo ln -s /etc/nginx/sites-available/familog.ka2.org /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx

```


6. **6. SSL証明書の取得 (Let's Encrypt):** HTTPS化の設定.
Certbot を使用して無料のSSL証明書を取得し、自動更新を設定します。

```bash
sudo certbot --nginx -d familog.ka2.org

```

対話形式の質問に答えると、Certbotが自動的にNginx設定ファイルにHTTPS用の証明書パスを追加してリロードしてくれます。


---

これで、要件定義からAPI・画面コード、IP制限付きの本番インフラデプロイ手順まで全仕様が完了いたしました。本設計をもとに構築を進めていただけます！
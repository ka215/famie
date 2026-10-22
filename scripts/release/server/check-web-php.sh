#!/usr/bin/env bash
# CoreServerなどのLinuxサーバー上で実行する。設定欄を編集して bash check-web-php.sh。
# リポジトリの配置処理とは独立しており、PHP設定の変更や再起動は行わない。
set -Eeuo pipefail
umask 077

# ---- 設定欄：同じ添字のFQDNとドキュメントルートが1組 ----
FQDNS=(
  'famie.co'
  'stg-famie.ka2.org'
  'famie.ka2.org'
)
DOCUMENT_ROOTS=(
  "$HOME/public_html/famie.co"
  "$HOME/public_html/stg-famie.ka2.org"
  "$HOME/public_html/famie.ka2.org"
)
# 実際の公開ディレクトリへ修正する。URLは https://FQDN/一時ファイル名.php。
PHP_SOURCE="$HOME/test/helthcheck.php"
REPORT_DIR="$HOME/test/reports"
EXPECTED_PHP_VERSION='8.4.27' # 完全一致。版の判定が不要なら空文字にする。
CONNECT_TIMEOUT=10
REQUEST_TIMEOUT=30
# ---- 設定欄ここまで ----

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
for tool in curl mktemp ln readlink rm mkdir date awk basename; do
  command -v "$tool" >/dev/null || fail "必要なコマンドがありません: $tool"
done
[[ ${#FQDNS[@]} -gt 0 && ${#FQDNS[@]} -eq ${#DOCUMENT_ROOTS[@]} ]] || fail '配列の件数を揃えてください。'
[[ $PHP_SOURCE == /* && -f $PHP_SOURCE && -r $PHP_SOURCE ]] || fail "PHPファイルを読めません: $PHP_SOURCE"
for i in "${!FQDNS[@]}"; do
  [[ ${FQDNS[$i]} =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] || fail "FQDNが不正です: ${FQDNS[$i]}"
  [[ ${DOCUMENT_ROOTS[$i]} == /* && -d ${DOCUMENT_ROOTS[$i]} && -w ${DOCUMENT_ROOTS[$i]} ]] || fail "公開ディレクトリを確認してください: ${DOCUMENT_ROOTS[$i]}"
  [[ ${DOCUMENT_ROOTS[$i]} != *$'\t'* && ${DOCUMENT_ROOTS[$i]} != *$'\n'* ]] || fail 'パスにタブ・改行は指定できません。'
done

work_dir=''
active_link=''
cleanup_link() {
  [[ -n $active_link ]] || return 0
  # 作成したリンクが同じ対象を指している場合だけ削除する。
  if [[ -L $active_link && $(readlink -- "$active_link") == "$PHP_SOURCE" ]]; then
    rm -- "$active_link" || return 1
  elif [[ -e $active_link || -L $active_link ]]; then
    printf 'ERROR: 一時パスが変更されたため削除しません: %s\n' "$active_link" >&2
    return 1
  fi
  active_link=''
}
cleanup() {
  local result=$?
  trap - EXIT
  if ! cleanup_link; then
    printf 'ERROR: 一時リンクの手動確認が必要です: %s\n' "$active_link" >&2
    result=1
  fi
  [[ -z $work_dir ]] || rm -rf -- "$work_dir" || result=1
  exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

mkdir -p -- "$REPORT_DIR"
report=$(mktemp "$REPORT_DIR/php-healthcheck-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX.tsv")
work_dir=$(mktemp -d "${TMPDIR:-/tmp}/famie-php-healthcheck.XXXXXXXX")
link_name="$(basename -- "$work_dir").php"
printf '# Checked at: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$report"
printf '# Expected PHP: %s (exact match; empty means no version check)\n' "$EXPECTED_PHP_VERSION" >> "$report"
printf 'FQDN\tDOCUMENT_ROOT\tHTTP\tCURL_EXIT\tPHP\tSAPI\tRESULT\n' >> "$report"
printf 'Report: %s\n' "$report"

failed=0
for i in "${!FQDNS[@]}"; do
  fqdn=${FQDNS[$i]}
  root=${DOCUMENT_ROOTS[$i]%/}
  link="$root/$link_name"
  # lnに-fを付けない。既存ファイル・リンクは上書きしない。
  if ! ln -sT -- "$PHP_SOURCE" "$link"; then
    printf '%s\t%s\t-\t-\t-\t-\tLINK_ERROR\n' "$fqdn" "$root" >> "$report"
    printf 'NG %s: リンク作成失敗\n' "$fqdn"
    failed=1
    continue
  fi
  active_link=$link
  curl_exit=0
  # TLS検証を有効にし、リダイレクトは追わない。Basic認証やIP制限は解除しない。
  http=$(curl --silent --show-error --proto '=https' \
    --connect-timeout "$CONNECT_TIMEOUT" --max-time "$REQUEST_TIMEOUT" \
    --max-filesize 65536 --header 'Cache-Control: no-cache' \
    --output "$work_dir/body" --write-out '%{http_code}' \
    "https://$fqdn/$link_name" 2> "$work_dir/curl-error") || curl_exit=$?

  # 本文全体はレポートへ残さない。想定した最小表示だけを抽出する。
  php=''
  sapi=''
  if [[ $curl_exit == 0 && $http == 200 ]]; then
    php=$(awk '/^PHP: [0-9]+\.[0-9]+\.[0-9]+([A-Za-z0-9.+~-]*)?\r?$/ { sub(/^PHP: /, ""); sub(/\r$/, ""); print; exit }' "$work_dir/body")
    sapi=$(awk '/^SAPI: [A-Za-z0-9_-]+\r?$/ { sub(/^SAPI: /, ""); sub(/\r$/, ""); print; exit }' "$work_dir/body")
  fi
  result=OK
  if [[ $curl_exit != 0 ]]; then result=CURL_ERROR
  elif [[ $http != 200 ]]; then result=HTTP_ERROR
  elif [[ -z $php || -z $sapi || $sapi == cli ]]; then result=INVALID_RESPONSE
  elif [[ -n $EXPECTED_PHP_VERSION && $php != "$EXPECTED_PHP_VERSION" ]]; then result=VERSION_MISMATCH
  fi
  if ! cleanup_link; then result=CLEANUP_ERROR; fi
  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$fqdn" "$root" "$http" "$curl_exit" "${php:--}" "${sapi:--}" "$result" >> "$report"
  printf '%s %s: HTTP=%s PHP=%s SAPI=%s\n' "$result" "$fqdn" "$http" "${php:--}" "${sapi:--}"
  if [[ $result != OK ]]; then failed=1; fi
  # 削除失敗時は次のリンクを作らず、EXITでも再度削除を試みる。
  if [[ $result == CLEANUP_ERROR ]]; then break; fi
done
printf '完了: %s\n' "$report"
exit "$failed"
